<?php

namespace App\Services;

use App\DTOs\ChangeTicketStatusData;
use App\DTOs\CreateTicketData;
use App\DTOs\TicketFilters;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCommentedNotification;
use App\Notifications\TicketResolvedNotification;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Capa de Servicios: contiene las reglas de negocio del ciclo de vida del ticket.
 * El controlador sólo valida la entrada y delega aquí.
 */
class TicketService
{
    public function __construct(
        private readonly TicketRepositoryInterface $tickets,
        private readonly TaxonomyRepositoryInterface $taxonomy,
        private readonly TicketStateMachine $stateMachine,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function list(User $viewer, TicketFilters $filters): LengthAwarePaginator
    {
        return $this->tickets->paginateFor($viewer, $filters);
    }

    public function find(int $id): Ticket
    {
        return $this->tickets->findOrFail($id);
    }

    /**
     * CU-U01: crea el incidente. Si no se indica prioridad, se infiere de la descripción.
     */
    public function create(CreateTicketData $data): Ticket
    {
        $priorityId = $data->priorityId ?? $this->inferPriorityId($data->title.' '.$data->description);

        return DB::transaction(function () use ($data, $priorityId) {
            $ticket = $this->tickets->create([
                'title' => $data->title,
                'description' => $data->description,
                'category_id' => $data->categoryId,
                'priority_id' => $priorityId,
                'created_by' => $data->createdBy,
                'status' => TicketStatus::OPEN,
            ]);

            $ticket->statusHistory()->create([
                'user_id' => $data->createdBy,
                'from_status' => null,
                'to_status' => TicketStatus::OPEN,
                'justification' => 'Ticket creado por el usuario.',
            ]);

            Log::info('ticket.created', ['ticket_id' => $ticket->id, 'user_id' => $data->createdBy]);

            return $ticket->load(['category', 'priority', 'creator']);
        });
    }

    /**
     * CU-T01 / CU-T02 / CU-T03: transición de estado atómica y auditada.
     *
     * @throws AuthorizationException
     * @throws InvalidStatusTransitionException
     */
    public function changeStatus(int $ticketId, ChangeTicketStatusData $data, User $actor): Ticket
    {
        $ticket = DB::transaction(function () use ($ticketId, $data, $actor) {
            $ticket = $this->tickets->lockForUpdate($ticketId);
            $from = $ticket->status;

            $this->stateMachine->assertTransition($ticket, $data->status, $actor);

            $attributes = ['status' => $data->status];

            if ($data->assignedToUserId !== null) {
                $attributes['assigned_to'] = $this->resolveAssignee($data->assignedToUserId)->id;
            } elseif ($data->status === TicketStatus::IN_PROGRESS && $ticket->assigned_to === null) {
                // Si el técnico toma el ticket sin indicar asignado, se lo asigna a sí mismo.
                $attributes['assigned_to'] = $actor->id;
            }

            if ($data->status === TicketStatus::RESOLVED) {
                if (blank($data->resolution)) {
                    throw ValidationException::withMessages([
                        'resolution' => 'Debe registrar la solución detallada al resolver el ticket.',
                    ]);
                }
                $attributes['resolution'] = $data->resolution;
                $attributes['resolved_at'] = now();
            }

            if ($data->status === TicketStatus::CLOSED) {
                $attributes['closed_at'] = now();
            }

            $this->tickets->update($ticket, $attributes);

            $ticket->statusHistory()->create([
                'user_id' => $actor->id,
                'from_status' => $from,
                'to_status' => $data->status,
                'assigned_to' => $attributes['assigned_to'] ?? null,
                'justification' => $data->justification,
            ]);

            Log::info('ticket.status_changed', [
                'ticket_id' => $ticket->id,
                'from' => $from->value,
                'to' => $data->status->value,
                'actor_id' => $actor->id,
            ]);

            return $ticket;
        });

        if ($data->status === TicketStatus::RESOLVED) {
            $ticket->creator->notify(new TicketResolvedNotification($ticket));
        }

        return $ticket->load(['category', 'priority', 'creator', 'assignee', 'statusHistory.actor']);
    }

    /**
     * CU-U03: agrega un comentario sin modificar el ticket y notifica a los responsables.
     */
    public function addComment(Ticket $ticket, User $author, string $body, ?string $attachmentPath = null): Comment
    {
        $comment = $ticket->comments()->create([
            'user_id' => $author->id,
            'body' => $body,
            'attachment_path' => $attachmentPath,
        ]);

        $recipients = collect([$ticket->assignee, $ticket->creator])
            ->filter()
            ->reject(fn (User $u) => $u->id === $author->id)
            ->unique('id');

        foreach ($recipients as $recipient) {
            $recipient->notify(new TicketCommentedNotification($ticket, $comment));
        }

        return $comment->load('author');
    }

    private function resolveAssignee(int $userId): User
    {
        $assignee = User::query()->findOrFail($userId);

        if (! $assignee->hasRole(Role::TECHNICIAN, Role::ADMIN)) {
            throw ValidationException::withMessages([
                'assignedToUserId' => 'El usuario asignado debe ser técnico o administrador.',
            ]);
        }

        return $assignee;
    }

    /**
     * Priorización automática basada en palabras clave de la descripción (CU-U01).
     */
    private function inferPriorityId(string $text): int
    {
        $text = mb_strtolower($text);
        $priorities = $this->taxonomy->priorities()->keyBy('name');

        $keywords = [
            'CRITICAL' => ['caído', 'caida', 'no funciona nada', 'producción', 'todos los usuarios', 'urgente'],
            'HIGH' => ['no puedo trabajar', 'bloqueado', 'error', 'falla', 'fallo'],
            'MEDIUM' => ['lento', 'intermitente', 'a veces'],
        ];

        foreach ($keywords as $name => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word) && $priorities->has($name)) {
                    return $priorities->get($name)->id;
                }
            }
        }

        return $this->taxonomy->defaultPriority()->id;
    }
}

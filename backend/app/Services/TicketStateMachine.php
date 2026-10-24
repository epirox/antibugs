<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Pattern State Machine: define qué transiciones de estado son válidas
 * y qué roles pueden ejecutarlas (CU-T02, sección 2 del documento).
 */
class TicketStateMachine
{
    /**
     * Transiciones permitidas: estado origen => lista de estados destino.
     *
     * @var array<string, list<TicketStatus>>
     */
    private const TRANSITIONS = [
        'OPEN' => [TicketStatus::ASSIGNED, TicketStatus::IN_PROGRESS],
        'ASSIGNED' => [TicketStatus::IN_PROGRESS, TicketStatus::OPEN],
        'IN_PROGRESS' => [TicketStatus::PENDING_INFO, TicketStatus::RESOLVED, TicketStatus::ASSIGNED],
        'PENDING_INFO' => [TicketStatus::IN_PROGRESS],
        'RESOLVED' => [TicketStatus::CLOSED, TicketStatus::IN_PROGRESS],
        'CLOSED' => [],
    ];

    /**
     * Roles autorizados a llevar un ticket a cada estado destino.
     *
     * @var array<string, list<Role>>
     */
    private const ALLOWED_ROLES = [
        'OPEN' => [Role::TECHNICIAN, Role::ADMIN],
        'ASSIGNED' => [Role::TECHNICIAN, Role::ADMIN],
        'IN_PROGRESS' => [Role::TECHNICIAN, Role::ADMIN],
        'PENDING_INFO' => [Role::TECHNICIAN, Role::ADMIN],
        'RESOLVED' => [Role::TECHNICIAN, Role::ADMIN],
        // El cierre formal lo confirma el usuario creador (CU-T03) o un admin.
        'CLOSED' => [Role::USER, Role::ADMIN],
    ];

    /**
     * @return list<TicketStatus>
     */
    public function allowedTransitions(TicketStatus $from): array
    {
        return self::TRANSITIONS[$from->value];
    }

    public function canTransition(TicketStatus $from, TicketStatus $to): bool
    {
        return in_array($to, $this->allowedTransitions($from), true);
    }

    /**
     * @throws AuthorizationException si el rol no puede ejecutar la transición (HTTP 403)
     * @throws InvalidStatusTransitionException si la transición viola las reglas (HTTP 422)
     */
    public function assertTransition(Ticket $ticket, TicketStatus $to, User $actor): void
    {
        $this->assertRoleAllowed($ticket, $to, $actor);

        $from = $ticket->status;

        if (! $this->canTransition($from, $to)) {
            throw new InvalidStatusTransitionException($from, $to);
        }

        if ($to === TicketStatus::RESOLVED && $ticket->assigned_to === null) {
            throw new InvalidStatusTransitionException(
                $from,
                $to,
                'No se puede resolver un ticket sin técnico asignado.'
            );
        }
    }

    private function assertRoleAllowed(Ticket $ticket, TicketStatus $to, User $actor): void
    {
        $allowed = self::ALLOWED_ROLES[$to->value];

        if (! $actor->hasRole(...$allowed)) {
            throw new AuthorizationException(
                sprintf('El rol %s no puede cambiar un ticket al estado %s.', $actor->role->value, $to->value)
            );
        }

        if ($actor->role === Role::USER && ! $ticket->isOwnedBy($actor)) {
            throw new AuthorizationException('Solo el creador del ticket puede confirmar su cierre.');
        }
    }
}

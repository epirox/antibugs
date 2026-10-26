<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Genera tickets históricos (creados, resueltos y cerrados en semanas pasadas)
 * para poblar los gráficos de estadísticas en una demo.
 */
class SeedDemoHistory extends Command
{
    protected $signature = 'antibugs:seed-history
        {--days=45 : Cantidad de días hacia atrás a poblar}
        {--max-per-day=3 : Máximo de tickets por día}
        {--seed=42 : Semilla aleatoria (resultados reproducibles)}
        {--fresh : Elimina los tickets históricos generados previamente}';

    protected $description = 'Genera tickets históricos de demostración para las estadísticas';

    public const DESCRIPTION_MARKER = 'Ticket histórico generado para la demostración de estadísticas.';

    private const TITLES = [
        'Impresora sin tóner', 'VPN no conecta', 'Teclado roto', 'Error al abrir Excel',
        'Pantalla azul al iniciar', 'Sin acceso a carpeta compartida', 'Correo rebota',
        'Monitor parpadea', 'Mouse no responde', 'Lentitud generalizada',
        'Wi-Fi intermitente', 'Licencia vencida', 'No puedo imprimir en red',
    ];

    public function handle(): int
    {
        $requesters = User::query()->where('role', Role::USER)->get();
        $staff = User::query()->whereIn('role', [Role::TECHNICIAN, Role::ADMIN])->get();
        $categories = Category::query()->pluck('id');
        $priorities = Priority::query()->pluck('id');

        if ($requesters->isEmpty() || $staff->isEmpty() || $categories->isEmpty() || $priorities->isEmpty()) {
            $this->error('Faltan usuarios, categorías o prioridades. Ejecutá primero: php artisan db:seed');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $deleted = Ticket::query()->where('description', self::DESCRIPTION_MARKER)->delete();
            $this->line("Se eliminaron {$deleted} tickets históricos previos.");
        }

        mt_srand((int) $this->option('seed'));
        $days = (int) $this->option('days');
        $maxPerDay = (int) $this->option('max-per-day');
        $created = 0;

        for ($d = $days; $d >= 1; $d--) {
            for ($i = mt_rand(0, $maxPerDay); $i > 0; $i--) {
                $this->createTicket(
                    Carbon::now()->subDays($d)->setTime(mt_rand(8, 18), mt_rand(0, 59)),
                    $requesters->random(),
                    $staff->random(),
                    $categories->random(),
                    $priorities->random(),
                );
                $created++;
            }
        }

        $this->info("Se generaron {$created} tickets históricos de los últimos {$days} días.");

        return self::SUCCESS;
    }

    private function createTicket(Carbon $createdAt, User $requester, User $assignee, int $categoryId, int $priorityId): void
    {
        // created_at/updated_at no son fillable: se desactiva la protección sólo para este bloque.
        Model::unguarded(function () use ($createdAt, $requester, $assignee, $categoryId, $priorityId): void {
            $this->createTicketUnguarded($createdAt, $requester, $assignee, $categoryId, $priorityId);
        });
    }

    private function createTicketUnguarded(Carbon $createdAt, User $requester, User $assignee, int $categoryId, int $priorityId): void
    {
        $resolvedAt = $createdAt->copy()->addHours(mt_rand(2, 96));
        $isResolved = mt_rand(0, 9) < 8 && $resolvedAt->isPast();
        $closedAt = $resolvedAt->copy()->addHours(mt_rand(4, 48));
        $isClosed = $isResolved && mt_rand(0, 1) === 1 && $closedAt->isPast();

        $status = match (true) {
            $isClosed => TicketStatus::CLOSED,
            $isResolved => TicketStatus::RESOLVED,
            mt_rand(0, 1) === 1 => TicketStatus::IN_PROGRESS,
            default => TicketStatus::OPEN,
        };

        $ticket = Ticket::query()->create([
            'title' => self::TITLES[array_rand(self::TITLES)],
            'description' => self::DESCRIPTION_MARKER,
            'status' => $status,
            'category_id' => $categoryId,
            'priority_id' => $priorityId,
            'created_by' => $requester->id,
            'assigned_to' => $status === TicketStatus::OPEN ? null : $assignee->id,
            'resolution' => $isResolved ? 'Resuelto satisfactoriamente.' : null,
            'resolved_at' => $isResolved ? $resolvedAt : null,
            'closed_at' => $isClosed ? $closedAt : null,
            'created_at' => $createdAt,
            'updated_at' => $isClosed ? $closedAt : ($isResolved ? $resolvedAt : $createdAt),
        ]);

        $takenAt = $createdAt->copy()->addMinutes(mt_rand(15, 240));

        $history = [[
            'user_id' => $requester->id,
            'from_status' => null,
            'to_status' => TicketStatus::OPEN,
            'justification' => 'Ticket creado por el usuario.',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]];

        if ($status !== TicketStatus::OPEN) {
            $history[] = [
                'user_id' => $assignee->id,
                'from_status' => TicketStatus::OPEN,
                'to_status' => TicketStatus::IN_PROGRESS,
                'assigned_to' => $assignee->id,
                'justification' => 'Tomo el caso, corresponde a mi especialidad.',
                'created_at' => $takenAt,
                'updated_at' => $takenAt,
            ];
        }

        if ($isResolved) {
            $history[] = [
                'user_id' => $assignee->id,
                'from_status' => TicketStatus::IN_PROGRESS,
                'to_status' => TicketStatus::RESOLVED,
                'justification' => 'Se aplicó la solución y se verificó con el usuario.',
                'created_at' => $resolvedAt,
                'updated_at' => $resolvedAt,
            ];
        }

        if ($isClosed) {
            $history[] = [
                'user_id' => $requester->id,
                'from_status' => TicketStatus::RESOLVED,
                'to_status' => TicketStatus::CLOSED,
                'justification' => 'Confirmo que la solución funcionó.',
                'created_at' => $closedAt,
                'updated_at' => $closedAt,
            ];
        }

        $ticket->statusHistory()->createMany($history);
    }
}

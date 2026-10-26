<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CU-A03: KPIs del departamento (sólo ADMIN) y exportación CSV.
 */
class ReportController extends Controller
{
    public function kpis(): JsonResponse
    {
        $byStatus = Ticket::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byCategory = Ticket::query()
            ->join('categories', 'categories.id', '=', 'tickets.category_id')
            ->select('categories.name', DB::raw('count(*) as total'))
            ->groupBy('categories.name')
            ->pluck('total', 'name');

        $byPriority = Ticket::query()
            ->join('priorities', 'priorities.id', '=', 'tickets.priority_id')
            ->select('priorities.name', DB::raw('count(*) as total'))
            ->groupBy('priorities.name')
            ->pluck('total', 'name');

        // Tiempo promedio de resolución calculado en PHP para ser agnóstico al motor (MySQL/SQLite).
        $resolved = Ticket::query()->whereNotNull('resolved_at')->get(['created_at', 'resolved_at']);
        $avgResolutionHours = $resolved->isEmpty()
            ? null
            : round($resolved->avg(fn (Ticket $t) => $t->created_at->diffInMinutes($t->resolved_at)) / 60, 2);

        $openStatuses = [TicketStatus::OPEN, TicketStatus::ASSIGNED, TicketStatus::IN_PROGRESS, TicketStatus::PENDING_INFO];

        return response()->json([
            'data' => [
                'total' => Ticket::query()->count(),
                'open' => Ticket::query()->whereIn('status', $openStatuses)->count(),
                'avgResolutionHours' => $avgResolutionHours,
                'createdLast24h' => Ticket::query()->where('created_at', '>=', now()->subDay())->count(),
                'byStatus' => $byStatus,
                'byCategory' => $byCategory,
                'byPriority' => $byPriority,
                'byTechnician' => $this->byTechnician(),
            ],
        ]);
    }

    /**
     * GET /api/v1/reports/timeline?days=30
     * Historia de resolución: tickets creados, resueltos y cerrados por día,
     * más el backlog (abiertos acumulados) al cierre de cada día.
     */
    public function timeline(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 30);
        $days = max(1, min($days, 365));

        $start = now()->subDays($days - 1)->startOfDay();

        $initialBacklog = Ticket::query()->where('created_at', '<', $start)->count()
            - Ticket::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $start)->count();

        $tickets = Ticket::query()
            ->where(fn ($q) => $q
                ->where('created_at', '>=', $start)
                ->orWhere('resolved_at', '>=', $start)
                ->orWhere('closed_at', '>=', $start))
            ->get(['created_at', 'resolved_at', 'closed_at']);

        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $buckets[$start->copy()->addDays($i)->toDateString()] = ['created' => 0, 'resolved' => 0, 'closed' => 0];
        }

        foreach ($tickets as $t) {
            foreach (['created' => $t->created_at, 'resolved' => $t->resolved_at, 'closed' => $t->closed_at] as $k => $at) {
                if ($at !== null && isset($buckets[$at->toDateString()])) {
                    $buckets[$at->toDateString()][$k]++;
                }
            }
        }

        $backlog = $initialBacklog;
        $series = [];
        foreach ($buckets as $date => $counts) {
            $backlog += $counts['created'] - $counts['resolved'];
            $series[] = ['date' => $date, ...$counts, 'backlog' => max(0, $backlog)];
        }

        $resolvedInRange = $tickets->filter(fn (Ticket $t) => $t->resolved_at !== null && $t->resolved_at >= $start);

        return response()->json([
            'data' => [
                'days' => $days,
                'from' => $start->toDateString(),
                'to' => now()->toDateString(),
                'series' => $series,
                'summary' => [
                    'created' => $tickets->filter(fn (Ticket $t) => $t->created_at >= $start)->count(),
                    'resolved' => $resolvedInRange->count(),
                    'avgResolutionHours' => $resolvedInRange->isEmpty()
                        ? null
                        : round($resolvedInRange->avg(fn (Ticket $t) => $t->created_at->diffInMinutes($t->resolved_at)) / 60, 2),
                ],
            ],
        ]);
    }

    /**
     * Rendimiento por técnico: tickets resueltos y tiempo promedio de resolución.
     *
     * @return list<array{id: int, name: string, resolved: int, open: int, avgResolutionHours: float|null}>
     */
    private function byTechnician(): array
    {
        $openStatuses = [TicketStatus::ASSIGNED, TicketStatus::IN_PROGRESS, TicketStatus::PENDING_INFO];

        return User::query()
            ->whereIn('role', [Role::TECHNICIAN, Role::ADMIN])
            ->with(['assignedTickets' => fn ($q) => $q->select('id', 'assigned_to', 'status', 'created_at', 'resolved_at')])
            ->orderBy('name')
            ->get()
            ->map(function (User $u) use ($openStatuses) {
                $resolved = $u->assignedTickets->whereNotNull('resolved_at');

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'resolved' => $resolved->count(),
                    'open' => $u->assignedTickets->whereIn('status', $openStatuses)->count(),
                    'avgResolutionHours' => $resolved->isEmpty()
                        ? null
                        : round($resolved->avg(fn (Ticket $t) => $t->created_at->diffInMinutes($t->resolved_at)) / 60, 2),
                ];
            })
            ->filter(fn (array $row) => $row['resolved'] > 0 || $row['open'] > 0)
            ->values()
            ->all();
    }

    public function export(): StreamedResponse
    {
        $filename = 'tickets-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'title', 'status', 'category', 'priority', 'created_by', 'assigned_to', 'created_at', 'resolved_at']);

            Ticket::query()
                ->with(['category', 'priority', 'creator', 'assignee'])
                ->orderBy('id')
                ->chunk(500, function ($tickets) use ($out) {
                    foreach ($tickets as $t) {
                        fputcsv($out, [
                            $t->id,
                            $t->title,
                            $t->status->value,
                            $t->category->name,
                            $t->priority->name,
                            $t->creator->email,
                            $t->assignee?->email,
                            $t->created_at,
                            $t->resolved_at,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}

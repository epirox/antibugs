<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
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
            ],
        ]);
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

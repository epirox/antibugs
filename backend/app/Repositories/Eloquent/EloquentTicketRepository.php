<?php

namespace App\Repositories\Eloquent;

use App\DTOs\TicketFilters;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentTicketRepository implements TicketRepositoryInterface
{
    public function findOrFail(int $id): Ticket
    {
        return Ticket::query()
            ->with(['category', 'priority', 'creator', 'assignee'])
            ->findOrFail($id);
    }

    public function lockForUpdate(int $id): Ticket
    {
        return Ticket::query()->lockForUpdate()->findOrFail($id);
    }

    public function paginateFor(User $viewer, TicketFilters $filters): LengthAwarePaginator
    {
        $query = Ticket::query()->with(['category', 'priority', 'creator', 'assignee']);

        // Un usuario común sólo ve sus propios tickets (CU-U02).
        if (! $viewer->isStaff()) {
            $query->where('created_by', $viewer->id);
        } elseif ($filters->onlyMine) {
            $query->where('assigned_to', $viewer->id);
        }

        $this->applyFilters($query, $filters);

        // Cola ordenada por prioridad (nivel más alto primero) y antigüedad.
        return $query
            ->join('priorities', 'priorities.id', '=', 'tickets.priority_id')
            ->orderByDesc('priorities.level')
            ->orderBy('tickets.created_at')
            ->select('tickets.*')
            ->paginate($filters->perPage);
    }

    public function create(array $attributes): Ticket
    {
        return Ticket::query()->create($attributes);
    }

    public function update(Ticket $ticket, array $attributes): Ticket
    {
        $ticket->fill($attributes)->save();

        return $ticket;
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applyFilters(Builder $query, TicketFilters $filters): void
    {
        $query
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('tickets.status', $status))
            ->when($filters->categoryId, fn (Builder $q, int $id) => $q->where('tickets.category_id', $id))
            ->when($filters->priorityId, fn (Builder $q, int $id) => $q->where('tickets.priority_id', $id))
            ->when($filters->assignedTo, fn (Builder $q, int $id) => $q->where('tickets.assigned_to', $id))
            ->when($filters->createdBy, fn (Builder $q, int $id) => $q->where('tickets.created_by', $id));
    }
}

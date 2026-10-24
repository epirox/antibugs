<?php

namespace App\Repositories\Contracts;

use App\DTOs\TicketFilters;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TicketRepositoryInterface
{
    public function findOrFail(int $id): Ticket;

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginateFor(User $viewer, TicketFilters $filters): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Ticket;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Ticket $ticket, array $attributes): Ticket;

    /**
     * Bloquea la fila (SELECT ... FOR UPDATE) para garantizar atomicidad de la transición.
     */
    public function lockForUpdate(int $id): Ticket;
}

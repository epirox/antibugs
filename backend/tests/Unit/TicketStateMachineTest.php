<?php

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketStateMachine;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Pruebas unitarias puras de la State Machine (sin base de datos).
 */
function fakeUser(Role $role, int $id = 1): User
{
    $user = new User(['name' => 'x', 'email' => "u{$id}@x.com", 'role' => $role]);
    $user->id = $id;

    return $user;
}

function fakeTicket(TicketStatus $status, ?int $assignedTo = null, int $createdBy = 1): Ticket
{
    $ticket = new Ticket(['status' => $status]);
    $ticket->assigned_to = $assignedTo;
    $ticket->created_by = $createdBy;

    return $ticket;
}

dataset('valid transitions', [
    'OPEN → ASSIGNED' => [TicketStatus::OPEN, TicketStatus::ASSIGNED],
    'OPEN → IN_PROGRESS' => [TicketStatus::OPEN, TicketStatus::IN_PROGRESS],
    'IN_PROGRESS → PENDING_INFO' => [TicketStatus::IN_PROGRESS, TicketStatus::PENDING_INFO],
    'PENDING_INFO → IN_PROGRESS' => [TicketStatus::PENDING_INFO, TicketStatus::IN_PROGRESS],
    'IN_PROGRESS → RESOLVED' => [TicketStatus::IN_PROGRESS, TicketStatus::RESOLVED],
    'RESOLVED → CLOSED' => [TicketStatus::RESOLVED, TicketStatus::CLOSED],
]);

dataset('invalid transitions', [
    'OPEN → RESOLVED' => [TicketStatus::OPEN, TicketStatus::RESOLVED],
    'OPEN → CLOSED' => [TicketStatus::OPEN, TicketStatus::CLOSED],
    'CLOSED → OPEN' => [TicketStatus::CLOSED, TicketStatus::OPEN],
    'PENDING_INFO → RESOLVED' => [TicketStatus::PENDING_INFO, TicketStatus::RESOLVED],
]);

it('allows valid transitions', function (TicketStatus $from, TicketStatus $to) {
    expect((new TicketStateMachine)->canTransition($from, $to))->toBeTrue();
})->with('valid transitions');

it('denies invalid transitions', function (TicketStatus $from, TicketStatus $to) {
    expect((new TicketStateMachine)->canTransition($from, $to))->toBeFalse();
})->with('invalid transitions');

it('throws InvalidStatusTransitionException for an invalid transition by a technician', function () {
    $sm = new TicketStateMachine;

    $sm->assertTransition(fakeTicket(TicketStatus::OPEN, assignedTo: 2), TicketStatus::RESOLVED, fakeUser(Role::TECHNICIAN, 2));
})->throws(InvalidStatusTransitionException::class);

it('throws AuthorizationException when a regular user tries to resolve', function () {
    $sm = new TicketStateMachine;

    $sm->assertTransition(fakeTicket(TicketStatus::IN_PROGRESS, assignedTo: 2), TicketStatus::RESOLVED, fakeUser(Role::USER));
})->throws(AuthorizationException::class);

it('does not allow resolving a ticket without an assigned technician', function () {
    $sm = new TicketStateMachine;

    $sm->assertTransition(fakeTicket(TicketStatus::IN_PROGRESS, assignedTo: null), TicketStatus::RESOLVED, fakeUser(Role::TECHNICIAN, 2));
})->throws(InvalidStatusTransitionException::class, 'sin técnico asignado');

it('only lets the ticket owner confirm the closure', function () {
    $sm = new TicketStateMachine;

    $sm->assertTransition(fakeTicket(TicketStatus::RESOLVED, assignedTo: 2, createdBy: 1), TicketStatus::CLOSED, fakeUser(Role::USER, 99));
})->throws(AuthorizationException::class);

it('has no transitions out of CLOSED', function () {
    expect((new TicketStateMachine)->allowedTransitions(TicketStatus::CLOSED))->toBeEmpty();
});

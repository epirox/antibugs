<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Casos de prueba concretos del documento (sección 5)
|--------------------------------------------------------------------------
*/

it('creates a ticket and returns 201 with its id (caso 1)', function () {
    ['category' => $category, 'high' => $high] = seedTaxonomy();
    $user = User::factory()->create();

    $response = actingAsApi($user)->postJson('/api/v1/tickets', [
        'title' => 'Fallo en impresora',
        'description' => 'No imprime en red',
        'categoryId' => $category->id,
        'priorityId' => $high->id,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'title', 'status', 'summary', 'allowedTransitions']])
        ->assertJsonPath('data.status', TicketStatus::OPEN->value)
        ->assertJsonPath('data.priority.name', 'HIGH')
        ->assertJsonPath('data.createdBy.id', $user->id);

    $this->assertDatabaseHas('tickets', ['id' => $response->json('data.id'), 'created_by' => $user->id]);
    $this->assertDatabaseHas('ticket_status_histories', [
        'ticket_id' => $response->json('data.id'),
        'to_status' => TicketStatus::OPEN->value,
    ]);
});

it('forbids a regular user from resolving a ticket with 403 (caso 2)', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->inProgress()->create(['created_by' => $user->id]);

    actingAsApi($user)->patchJson("/api/v1/tickets/{$ticket->id}/status", [
        'status' => TicketStatus::RESOLVED->value,
        'justification' => 'Yo mismo lo arreglé, lo cierro.',
    ])->assertForbidden();

    expect($ticket->fresh()->status)->toBe(TicketStatus::IN_PROGRESS);
});

it('returns 404 for a non-existent ticket (caso 3)', function () {
    actingAsApi(User::factory()->technician()->create())
        ->getJson('/api/v1/tickets/9999')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

/*
|--------------------------------------------------------------------------
| State Machine y reglas de negocio adicionales
|--------------------------------------------------------------------------
*/

it('lets a technician take an OPEN ticket to IN_PROGRESS and records the history', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create();

    actingAsApi($tech)->patchJson("/api/v1/tickets/{$ticket->id}/status", [
        'status' => TicketStatus::IN_PROGRESS->value,
        'justification' => 'Tomo el caso, corresponde a mi especialidad.',
    ])->assertOk()
        ->assertJsonPath('data.status', TicketStatus::IN_PROGRESS->value)
        ->assertJsonPath('data.assignedTo.id', $tech->id)
        ->assertJsonCount(1, 'data.history');

    $this->assertDatabaseHas('ticket_status_histories', [
        'ticket_id' => $ticket->id,
        'user_id' => $tech->id,
        'from_status' => TicketStatus::OPEN->value,
        'to_status' => TicketStatus::IN_PROGRESS->value,
        'justification' => 'Tomo el caso, corresponde a mi especialidad.',
    ]);
});

it('rejects an invalid transition with 422 (OPEN → RESOLVED)', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create();

    actingAsApi($tech)->patchJson("/api/v1/tickets/{$ticket->id}/status", [
        'status' => TicketStatus::RESOLVED->value,
        'justification' => 'Intento saltarme el flujo.',
        'resolution' => 'Reinicié el equipo y funcionó.',
    ])->assertUnprocessable()
        ->assertJsonPath('from', TicketStatus::OPEN->value)
        ->assertJsonPath('to', TicketStatus::RESOLVED->value);

    expect($ticket->fresh()->status)->toBe(TicketStatus::OPEN);
});

it('requires a written justification for every status change', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create();

    actingAsApi($tech)->patchJson("/api/v1/tickets/{$ticket->id}/status", [
        'status' => TicketStatus::IN_PROGRESS->value,
    ])->assertUnprocessable()->assertJsonValidationErrors('justification');
});

it('notifies the creator when the ticket is resolved', function () {
    Notification::fake();

    $tech = User::factory()->technician()->create();
    $creator = User::factory()->create();
    $ticket = Ticket::factory()->inProgress($tech)->create(['created_by' => $creator->id]);

    actingAsApi($tech)->patchJson("/api/v1/tickets/{$ticket->id}/status", [
        'status' => TicketStatus::RESOLVED->value,
        'justification' => 'Se reemplazó el cable de red defectuoso.',
        'resolution' => 'Cable de red reemplazado y conectividad verificada.',
    ])->assertOk()->assertJsonPath('data.status', TicketStatus::RESOLVED->value);

    Notification::assertSentTo($creator, TicketResolvedNotification::class);
});

it('lets the creator close a RESOLVED ticket', function () {
    $creator = User::factory()->create();
    $ticket = Ticket::factory()->resolved()->create(['created_by' => $creator->id]);

    actingAsApi($creator)->patchJson("/api/v1/tickets/{$ticket->id}/status", [
        'status' => TicketStatus::CLOSED->value,
        'justification' => 'Confirmo que la solución funcionó, gracias.',
    ])->assertOk()->assertJsonPath('data.status', TicketStatus::CLOSED->value);
});

/*
|--------------------------------------------------------------------------
| Visibilidad por rol (CU-U02 / CU-T01)
|--------------------------------------------------------------------------
*/

it('only shows a regular user their own tickets', function () {
    $me = User::factory()->create();
    Ticket::factory()->count(2)->create(['created_by' => $me->id]);
    Ticket::factory()->create();

    actingAsApi($me)->getJson('/api/v1/tickets')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('forbids a regular user from viewing another user\'s ticket', function () {
    $other = Ticket::factory()->create();

    actingAsApi(User::factory()->create())
        ->getJson("/api/v1/tickets/{$other->id}")
        ->assertForbidden();
});

it('shows the technician the full queue ordered by priority', function () {
    ['category' => $category, 'low' => $low, 'high' => $high] = seedTaxonomy();
    $tech = User::factory()->technician()->create();

    $lowTicket = Ticket::factory()->create(['category_id' => $category->id, 'priority_id' => $low->id]);
    $highTicket = Ticket::factory()->create(['category_id' => $category->id, 'priority_id' => $high->id]);

    actingAsApi($tech)->getJson('/api/v1/tickets')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $highTicket->id)
        ->assertJsonPath('data.1.id', $lowTicket->id);
});

it('lets a user comment on their ticket', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->create(['created_by' => $user->id]);

    actingAsApi($user)->postJson("/api/v1/tickets/{$ticket->id}/comments", [
        'body' => 'Agrego más detalles: el error aparece sólo al imprimir PDF.',
    ])->assertCreated()->assertJsonPath('data.author.id', $user->id);

    $this->assertDatabaseCount('comments', 1);
});

it('blocks non-admin users from admin endpoints', function () {
    actingAsApi(User::factory()->technician()->create())
        ->getJson('/api/v1/reports/kpis')
        ->assertForbidden();
});

it('lets staff list technicians to escalate a ticket, but not regular users', function () {
    User::factory()->technician()->count(2)->create();
    User::factory()->admin()->create();
    User::factory()->count(3)->create();

    actingAsApi(User::factory()->technician()->create())
        ->getJson('/api/v1/technicians')
        ->assertOk()
        ->assertJsonCount(4, 'data');

    actingAsApi(User::factory()->create())
        ->getJson('/api/v1/technicians')
        ->assertForbidden();
});

it('returns KPIs for admins', function () {
    Ticket::factory()->resolved()->create();
    Ticket::factory()->create();

    actingAsApi(User::factory()->admin()->create())
        ->getJson('/api/v1/reports/kpis')
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.open', 1)
        ->assertJsonStructure(['data' => ['avgResolutionHours', 'byStatus', 'byCategory', 'byPriority']]);
});

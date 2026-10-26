<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;

it('returns a daily timeline of created, resolved and backlog', function () {
    Carbon::setTestNow('2026-10-26 12:00:00');

    $tech = User::factory()->technician()->create();

    // Fuera de rango (hace 10 días), sigue abierto → backlog inicial = 1
    Ticket::factory()->create(['created_at' => now()->subDays(10)]);

    // Creado hace 3 días, resuelto hace 1 día
    Ticket::factory()->create([
        'status' => TicketStatus::RESOLVED,
        'assigned_to' => $tech->id,
        'created_at' => now()->subDays(3),
        'resolved_at' => now()->subDay(),
        'resolution' => 'ok',
    ]);

    // Creados hoy
    Ticket::factory()->count(2)->create(['created_at' => now()]);

    $response = actingAsApi(User::factory()->admin()->create())
        ->getJson('/api/v1/reports/timeline?days=7')
        ->assertOk()
        ->assertJsonPath('data.days', 7)
        ->assertJsonCount(7, 'data.series')
        ->assertJsonPath('data.summary.created', 3)
        ->assertJsonPath('data.summary.resolved', 1)
        ->assertJsonPath('data.summary.avgResolutionHours', 48);

    $series = collect($response->json('data.series'))->keyBy('date');

    expect($series->get('2026-10-20')['backlog'])->toBe(1)
        ->and($series->get('2026-10-23')['created'])->toBe(1)
        ->and($series->get('2026-10-23')['backlog'])->toBe(2)
        ->and($series->get('2026-10-25')['resolved'])->toBe(1)
        ->and($series->get('2026-10-25')['backlog'])->toBe(1)
        ->and($series->get('2026-10-26')['created'])->toBe(2)
        ->and($series->get('2026-10-26')['backlog'])->toBe(3);

    Carbon::setTestNow();
});

it('caps the timeline range between 1 and 365 days', function () {
    $admin = User::factory()->admin()->create();

    actingAsApi($admin)->getJson('/api/v1/reports/timeline?days=0')->assertJsonPath('data.days', 1);
    actingAsApi($admin)->getJson('/api/v1/reports/timeline?days=9999')->assertJsonPath('data.days', 365);
});

it('breaks down resolved tickets by technician in the KPIs', function () {
    $tech = User::factory()->technician()->create(['name' => 'Juan']);
    User::factory()->technician()->create(['name' => 'Sin trabajo']);

    Ticket::factory()->resolved($tech)->count(2)->create();
    Ticket::factory()->inProgress($tech)->create();

    actingAsApi(User::factory()->admin()->create())
        ->getJson('/api/v1/reports/kpis')
        ->assertOk()
        ->assertJsonCount(1, 'data.byTechnician')
        ->assertJsonPath('data.byTechnician.0.name', 'Juan')
        ->assertJsonPath('data.byTechnician.0.resolved', 2)
        ->assertJsonPath('data.byTechnician.0.open', 1);
});

it('forbids non-admins from the timeline', function () {
    actingAsApi(User::factory()->technician()->create())
        ->getJson('/api/v1/reports/timeline')
        ->assertForbidden();
});

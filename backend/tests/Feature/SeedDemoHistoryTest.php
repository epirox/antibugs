<?php

use App\Console\Commands\SeedDemoHistory;
use App\Models\Ticket;
use App\Models\User;

it('generates historical tickets with past timestamps and consistent resolution dates', function () {
    seedTaxonomy();
    User::factory()->create();
    User::factory()->technician()->create();

    $this->artisan('antibugs:seed-history', ['--days' => 20, '--max-per-day' => 2])
        ->assertSuccessful();

    $tickets = Ticket::query()->where('description', SeedDemoHistory::DESCRIPTION_MARKER)->get();

    expect($tickets)->not->toBeEmpty();

    // Los created_at deben respetarse (no ser "ahora") y resolved_at nunca anterior a created_at.
    expect($tickets->filter(fn (Ticket $t) => $t->created_at->isToday())->count())->toBeLessThan($tickets->count());
    expect($tickets->whereNotNull('resolved_at')->every(fn (Ticket $t) => $t->resolved_at->greaterThan($t->created_at)))->toBeTrue();
    expect($tickets->whereNotNull('closed_at')->every(fn (Ticket $t) => $t->closed_at->greaterThan($t->resolved_at)))->toBeTrue();
    expect($tickets->every(fn (Ticket $t) => $t->statusHistory()->count() >= 1))->toBeTrue();
});

it('replaces previous demo history with --fresh', function () {
    seedTaxonomy();
    User::factory()->create();
    User::factory()->technician()->create();

    $this->artisan('antibugs:seed-history', ['--days' => 10])->assertSuccessful();
    $first = Ticket::query()->count();

    $this->artisan('antibugs:seed-history', ['--days' => 10, '--fresh' => true])->assertSuccessful();

    expect(Ticket::query()->count())->toBe($first);
});

it('fails gracefully when there is no base data', function () {
    $this->artisan('antibugs:seed-history')->assertFailed();
});

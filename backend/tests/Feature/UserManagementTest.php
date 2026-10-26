<?php

use App\Enums\Role;
use App\Models\User;

it('lets an admin list, create, update and delete users', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->technician()->count(2)->create();

    actingAsApi($admin)->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['meta' => ['current_page', 'last_page', 'total']]);

    actingAsApi($admin)->getJson('/api/v1/users?role=technician')->assertJsonCount(2, 'data');

    $created = actingAsApi($admin)->postJson('/api/v1/users', [
        'name' => 'Nuevo Técnico',
        'email' => 'nuevo@antibugs.local',
        'password' => 'secret123',
        'role' => 'TECHNICIAN',
    ])->assertCreated()->assertJsonPath('data.role', 'TECHNICIAN');

    $id = $created->json('data.id');

    actingAsApi($admin)->patchJson("/api/v1/users/{$id}", ['role' => 'ADMIN', 'name' => 'Ascendido'])
        ->assertOk()
        ->assertJsonPath('data.role', 'ADMIN')
        ->assertJsonPath('data.name', 'Ascendido');

    actingAsApi($admin)->deleteJson("/api/v1/users/{$id}")->assertNoContent();
    $this->assertDatabaseMissing('users', ['id' => $id]);
});

it('rejects duplicate emails and weak passwords', function () {
    $admin = User::factory()->admin()->create(['email' => 'admin@antibugs.local']);

    actingAsApi($admin)->postJson('/api/v1/users', [
        'name' => 'X',
        'email' => 'admin@antibugs.local',
        'password' => '123',
        'role' => 'USER',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
});

it('prevents an admin from deleting themselves or dropping their own admin role', function () {
    $admin = User::factory()->admin()->create();

    actingAsApi($admin)->deleteJson("/api/v1/users/{$admin->id}")->assertStatus(422);
    actingAsApi($admin)->patchJson("/api/v1/users/{$admin->id}", ['role' => 'USER'])->assertStatus(422);
    actingAsApi($admin)->patchJson("/api/v1/users/{$admin->id}", ['name' => 'Renombrado'])->assertOk();

    expect($admin->fresh()->role)->toBe(Role::ADMIN);
});

it('blocks technicians and users from user management', function () {
    actingAsApi(User::factory()->technician()->create())->getJson('/api/v1/users')->assertForbidden();
    actingAsApi(User::factory()->create())->postJson('/api/v1/users', [])->assertForbidden();
});

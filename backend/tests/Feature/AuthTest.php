<?php

use App\Models\User;

it('returns a JWT when credentials are valid', function () {
    $user = User::factory()->create(['email' => 'user@antibugs.local', 'password' => 'secret123']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'user@antibugs.local',
        'password' => 'secret123',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['accessToken', 'tokenType', 'expiresIn', 'user' => ['id', 'email', 'role']])
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('tokenType', 'Bearer');
});

it('rejects invalid credentials with 422', function () {
    User::factory()->create(['email' => 'user@antibugs.local', 'password' => 'secret123']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'user@antibugs.local',
        'password' => 'wrong',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('allows the token to be used as Bearer on protected routes', function () {
    User::factory()->create(['email' => 'user@antibugs.local', 'password' => 'secret123']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'user@antibugs.local',
        'password' => 'secret123',
    ])->json('accessToken');

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'user@antibugs.local');
});

it('returns 401 without a token', function () {
    $this->getJson('/api/v1/tickets')->assertUnauthorized();
});

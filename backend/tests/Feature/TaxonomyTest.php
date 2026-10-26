<?php

use App\Models\Category;
use App\Models\Priority;
use App\Models\User;
use App\Repositories\Eloquent\CachedTaxonomyRepository;
use Illuminate\Support\Facades\Cache;

it('lists active categories and priorities for a regular user', function () {
    Category::factory()->create(['name' => 'Hardware']);
    Category::factory()->create(['name' => 'Obsoleta', 'is_active' => false]);
    Priority::factory()->low()->create();
    Priority::factory()->high()->create();

    $user = User::factory()->create();

    actingAsApi($user)->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Hardware');

    actingAsApi($user)->getJson('/api/v1/priorities')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'HIGH');
});

/*
 * Regresión: Laravel 13 bloquea la deserialización de objetos desde la caché
 * (cache.serializable_classes = false). Cachear modelos Eloquent provocaba
 * un 500 en Redis, por lo que la caché debe contener sólo arrays planos.
 */
it('stores only plain arrays in the taxonomy cache', function () {
    Category::factory()->count(2)->create();
    Priority::factory()->low()->create();

    $repo = app(CachedTaxonomyRepository::class);
    $repo->flushCache();
    $repo->activeCategories();
    $repo->priorities();

    foreach ([CachedTaxonomyRepository::KEY_CATEGORIES, CachedTaxonomyRepository::KEY_PRIORITIES] as $key) {
        $cached = Cache::get($key);

        expect($cached)->toBeArray()->not->toBeEmpty();
        expect(unserialize(serialize($cached), ['allowed_classes' => false]))->toEqual($cached);
    }
});

it('rehydrates cached rows as Eloquent models', function () {
    Category::factory()->create(['name' => 'Redes']);
    Priority::factory()->low()->create();
    Priority::factory()->high()->create();

    $repo = app(CachedTaxonomyRepository::class);
    $repo->flushCache();

    $categories = $repo->activeCategories();
    $categories = $repo->activeCategories(); // segunda lectura: viene de caché

    expect($categories->first())->toBeInstanceOf(Category::class)
        ->and($categories->first()->name)->toBe('Redes')
        ->and($repo->defaultPriority())->toBeInstanceOf(Priority::class)
        ->and($repo->defaultPriority()->name)->toBe('LOW');
});

it('invalidates the cache when an admin creates a category', function () {
    Category::factory()->create(['name' => 'Hardware']);
    $admin = User::factory()->admin()->create();

    actingAsApi($admin)->getJson('/api/v1/categories')->assertJsonCount(1, 'data');

    actingAsApi($admin)->postJson('/api/v1/categories', ['name' => 'Software'])->assertCreated();

    actingAsApi($admin)->getJson('/api/v1/categories')->assertJsonCount(2, 'data');
});

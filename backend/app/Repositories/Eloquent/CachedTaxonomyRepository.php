<?php

namespace App\Repositories\Eloquent;

use App\Models\Category;
use App\Models\Priority;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Capa de caché (Redis): las lecturas frecuentes de categorías y prioridades
 * se sirven desde caché y sólo se invalidan cuando el Admin las modifica (CU-A02).
 *
 * Se cachean arrays planos (no modelos): Laravel 13 bloquea por defecto la
 * deserialización de objetos desde la caché (`cache.serializable_classes = false`)
 * para prevenir ataques de "gadget chain". Al leer, se rehidratan los modelos.
 */
class CachedTaxonomyRepository implements TaxonomyRepositoryInterface
{
    private const TTL_SECONDS = 3600;

    public const KEY_CATEGORIES = 'taxonomy:categories:active';

    public const KEY_PRIORITIES = 'taxonomy:priorities';

    public function activeCategories(): Collection
    {
        $rows = Cache::remember(
            self::KEY_CATEGORIES,
            self::TTL_SECONDS,
            fn () => Category::query()->where('is_active', true)->orderBy('name')->get()->toArray()
        );

        return Category::hydrate($rows);
    }

    public function priorities(): Collection
    {
        $rows = Cache::remember(
            self::KEY_PRIORITIES,
            self::TTL_SECONDS,
            fn () => Priority::query()->orderByDesc('level')->get()->toArray()
        );

        return Priority::hydrate($rows);
    }

    public function defaultPriority(): Priority
    {
        return $this->priorities()->sortBy('level')->firstOrFail();
    }

    public function createCategory(array $attributes): Category
    {
        $category = Category::query()->create($attributes);
        $this->flushCache();

        return $category;
    }

    public function updateCategory(Category $category, array $attributes): Category
    {
        $category->fill($attributes)->save();
        $this->flushCache();

        return $category;
    }

    public function createPriority(array $attributes): Priority
    {
        $priority = Priority::query()->create($attributes);
        $this->flushCache();

        return $priority;
    }

    public function flushCache(): void
    {
        Cache::forget(self::KEY_CATEGORIES);
        Cache::forget(self::KEY_PRIORITIES);
    }
}

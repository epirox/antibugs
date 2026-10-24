<?php

namespace App\Repositories\Contracts;

use App\Models\Category;
use App\Models\Priority;
use Illuminate\Support\Collection;

interface TaxonomyRepositoryInterface
{
    /** @return Collection<int, Category> */
    public function activeCategories(): Collection;

    /** @return Collection<int, Priority> */
    public function priorities(): Collection;

    public function defaultPriority(): Priority;

    /** @param array<string, mixed> $attributes */
    public function createCategory(array $attributes): Category;

    /** @param array<string, mixed> $attributes */
    public function updateCategory(Category $category, array $attributes): Category;

    /** @param array<string, mixed> $attributes */
    public function createPriority(array $attributes): Priority;

    public function flushCache(): void;
}

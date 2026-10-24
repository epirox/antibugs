<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU-A02: taxonomía (categorías / prioridades). Lecturas cacheadas en Redis.
 */
class TaxonomyController extends Controller
{
    public function __construct(private readonly TaxonomyRepositoryInterface $taxonomy) {}

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->taxonomy->activeCategories()]);
    }

    public function priorities(): JsonResponse
    {
        return response()->json(['data' => $this->taxonomy->priorities()]);
    }

    public function storeCategory(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->taxonomy->createCategory($request->validated());

        return response()->json(['data' => $category], 201);
    }

    public function updateCategory(StoreCategoryRequest $request, Category $category): JsonResponse
    {
        return response()->json(['data' => $this->taxonomy->updateCategory($category, $request->validated())]);
    }

    public function storePriority(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:20', 'unique:priorities,name'],
            'level' => ['required', 'integer', 'min:1', 'max:10', 'unique:priorities,level'],
            'sla_hours' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->taxonomy->createPriority($validated)], 201);
    }
}

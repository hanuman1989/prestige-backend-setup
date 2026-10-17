<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $categories = Category::query()->where('status', true)->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 15)->appends($filters);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve categories. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CategoryResource::collection($categories),
            'pagination' => [
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
                'from' => $categories->firstItem(),
                'to' => $categories->lastItem(),
                'next_page_url' => $categories->nextPageUrl(),
                'prev_page_url' => $categories->previousPageUrl(),
            ],
            'message' => 'Categories retrieved successfully.',
        ]);
    }

    public function show(Request $request, string $category): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $category = Category::query()->where('status', true)->findOrFail($category);
            $packages = $category->packages()->published()->with([
                'destinations' => fn (BelongsToMany $destinations): BelongsToMany => $destinations->where('status', true),
                'images' => fn (HasMany $images): HasMany => $images->limit(1),
            ])->orderByDesc('packages.id')->paginate($filters['per_page'] ?? 15)->appends($filters);
            $category->setRelation('packages', $packages->getCollection());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve the category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CategoryResource($category),
            'pagination' => [
                'current_page' => $packages->currentPage(),
                'last_page' => $packages->lastPage(),
                'per_page' => $packages->perPage(),
                'total' => $packages->total(),
                'from' => $packages->firstItem(),
                'to' => $packages->lastItem(),
                'next_page_url' => $packages->nextPageUrl(),
                'prev_page_url' => $packages->previousPageUrl(),
            ],
            'message' => 'Category retrieved successfully.',
        ]);
    }

    public function getPopularCategories(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            $categories = Category::query()->where('status', true)->where('is_most_popular', true)
                ->orderByDesc('id')->limit($filters['limit'] ?? 20)->get();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve popular categories. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CategoryResource::collection($categories),
            'pagination' => null,
            'message' => 'Popular categories retrieved successfully.',
        ]);
    }
}

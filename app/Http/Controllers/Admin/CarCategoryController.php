<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCarCategoryRequest;
use App\Http\Requests\Admin\UpdateCarCategoryRequest;
use App\Http\Resources\CarCategoryResource;
use App\Models\CarCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;

class CarCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = CarCategory::query();
            $keyword = trim($filters['keyword'] ?? '');

            if ($keyword !== '') {
                $searchPattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';

                $query->where(function (Builder $query) use ($searchPattern): void {
                    $query->whereRaw("title LIKE ? ESCAPE '!'", [$searchPattern])
                        ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$searchPattern]);
                });
            }

            if (isset($filters['status'])) {
                $query->where('status', (bool) $filters['status']);
            }

            $carCategories = $query->orderByDesc('id')
                ->paginate((int) ($filters['per_page'] ?? 15))
                ->appends($filters);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve car categories. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CarCategoryResource::collection($carCategories),
            'pagination' => [
                'current_page' => $carCategories->currentPage(),
                'last_page' => $carCategories->lastPage(),
                'per_page' => $carCategories->perPage(),
                'total' => $carCategories->total(),
                'from' => $carCategories->firstItem(),
                'to' => $carCategories->lastItem(),
                'next_page_url' => $carCategories->nextPageUrl(),
                'prev_page_url' => $carCategories->previousPageUrl(),
            ],
            'message' => 'Car categories retrieved successfully.',
        ]);
    }

    public function store(StoreCarCategoryRequest $request): JsonResponse
    {
        try {
            $carCategory = DB::transaction(function () use ($request): CarCategory {
                $carCategory = new CarCategory($request->safe()->except('image'));
                $carCategory->uploadedImage = $request->file('image');
                $carCategory->save();

                return $carCategory->refresh();
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the car category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CarCategoryResource($carCategory),
            'pagination' => null,
            'message' => 'Car category created successfully.',
        ], 201);
    }

    public function show(CarCategory $carCategory): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => new CarCategoryResource($carCategory),
            'pagination' => null,
            'message' => 'Car category retrieved successfully.',
        ]);
    }

    public function update(UpdateCarCategoryRequest $request, CarCategory $carCategory): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $carCategory): void {
                $carCategory->fill($request->safe()->except('image'));
                $carCategory->uploadedImage = $request->file('image');
                $carCategory->save();
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the car category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CarCategoryResource($carCategory),
            'message' => 'Car category updated successfully.',
        ]);
    }

    public function destroy(CarCategory $carCategory): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($carCategory): bool {
                if ($carCategory->cars()->exists()) {
                    return false;
                }

                return (bool) $carCategory->delete();
            });
        } catch (QueryException|PDOException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [19, 787, 1451, 1811], true)
                && str_contains(strtolower($exception->getMessage()), 'foreign key')) {
                $deleted = false;
            } else {
                return $this->databaseFailure($exception, 'Unable to delete the car category. Please try again later.');
            }
        }

        if (! $deleted) {
            return response()->json([
                'status' => false,
                'data' => null,
                'pagination' => null,
                'message' => 'Cannot delete a car category that has cars.',
            ], 409);
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Car category deleted successfully.',
        ]);
    }
}

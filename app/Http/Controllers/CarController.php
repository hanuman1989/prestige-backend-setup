<?php

namespace App\Http\Controllers;

use App\Http\Resources\CarCategoryResource;
use App\Http\Resources\CarResource;
use App\Models\Car;
use App\Models\CarCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PDOException;

class CarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'car_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('car_categories', 'id')->where('status', true)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = Car::query()->where('status', true)
                ->whereHas('carCategory', fn (Builder $category): Builder => $category->where('status', true))
                ->with('carCategory');

            if (isset($filters['car_category_id'])) {
                $query->where('car_category_id', (int) $filters['car_category_id']);
            }

            $cars = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 15)->appends($filters);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve cars. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CarResource::collection($cars),
            'pagination' => [
                'current_page' => $cars->currentPage(),
                'last_page' => $cars->lastPage(),
                'per_page' => $cars->perPage(),
                'total' => $cars->total(),
                'from' => $cars->firstItem(),
                'to' => $cars->lastItem(),
                'next_page_url' => $cars->nextPageUrl(),
                'prev_page_url' => $cars->previousPageUrl(),
            ],
            'message' => 'Cars retrieved successfully.',
        ]);
    }

    public function show(string $car): JsonResponse
    {
        try {
            $car = Car::query()->where('status', true)
                ->whereHas('carCategory', fn (Builder $category): Builder => $category->where('status', true))
                ->with('carCategory')->findOrFail($car);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve the car. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CarResource($car),
            'pagination' => null,
            'message' => 'Car retrieved successfully.',
        ]);
    }

    public function getRecommendedCategories(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            $categories = CarCategory::query()->where('status', true)->where('is_recommended', true)
                ->withCount('cars')
                ->orderByDesc('id')->limit($filters['limit'] ?? 10)->get();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve recommended car categories. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CarCategoryResource::collection($categories),
            'pagination' => null,
            'message' => 'Recommended car categories retrieved successfully.',
        ]);
    }

    public function getCarCategories(): JsonResponse
    {
        try {
            $categories = CarCategory::query()->where('status', true)
                ->orderByDesc('is_recommended')->orderBy('title')->orderByDesc('id')->limit(100)->get();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve car categories. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CarCategoryResource::collection($categories),
            'pagination' => null,
            'message' => 'Car categories retrieved successfully.',
        ]);
    }

    public function categoryDetail(string $category): JsonResponse
    {
        try {
            $category = CarCategory::query()->where('status', true)->where('slug', $category)->firstOrFail();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve the car category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CarCategoryResource($category),
            'pagination' => null,
            'message' => 'Car category retrieved successfully.',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCarRequest;
use App\Http\Requests\Admin\UpdateCarRequest;
use App\Http\Resources\CarResource;
use App\Models\Car;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PDOException;

class CarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'boolean'],
            'car_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('car_categories', 'id')],
            'is_featured' => ['sometimes', 'nullable', 'boolean'],
            'transmission' => ['sometimes', 'nullable', Rule::in(['manual', 'automatic'])],
            'rate_basis' => ['sometimes', 'nullable', Rule::in(['per_km', 'per_day', 'per_trip'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = Car::query()->with('carCategory');
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

            foreach (['car_category_id', 'is_featured', 'transmission', 'rate_basis'] as $filter) {
                if (isset($filters[$filter])) {
                    $query->where($filter, $filters[$filter]);
                }
            }

            $cars = $query->orderByDesc('id')
                ->paginate((int) ($filters['per_page'] ?? 15))
                ->appends($filters);
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

    public function store(StoreCarRequest $request): JsonResponse
    {
        try {
            $car = DB::transaction(function () use ($request): Car {
                $car = new Car($request->safe()->except('image'));
                $car->uploadedImage = $request->file('image');
                $car->save();

                return $car->refresh()->load('carCategory');
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the car. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CarResource($car),
            'pagination' => null,
            'message' => 'Car created successfully.',
        ], 201);
    }

    public function show(Car $car): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => new CarResource($car->load('carCategory')),
            'pagination' => null,
            'message' => 'Car retrieved successfully.',
        ]);
    }

    public function update(UpdateCarRequest $request, Car $car): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $car): void {
                $car->fill($request->safe()->except('image'));
                $car->uploadedImage = $request->file('image');
                $car->save();
                $car->load('carCategory');
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the car. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CarResource($car),
            'pagination' => null,
            'message' => 'Car updated successfully.',
        ]);
    }

    public function destroy(Car $car): JsonResponse
    {
        try {
            DB::transaction(fn (): ?bool => $car->delete());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the car. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Car deleted successfully.',
        ]);
    }
}

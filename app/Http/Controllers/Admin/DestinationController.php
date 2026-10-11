<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDestinationRequest;
use App\Http\Requests\Admin\UpdateDestinationRequest;
use App\Http\Resources\DestinationResource;
use App\Models\Destination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PDOException;

class DestinationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destination_type' => ['sometimes', 'nullable', 'integer', Rule::in([1, 2])],
            'status' => ['sometimes', 'nullable', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = Destination::query();
            $search = trim($filters['search'] ?? '');

            if ($search !== '') {
                $searchPattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

                $query->where(function (Builder $query) use ($searchPattern): void {
                    $query->whereRaw("title LIKE ? ESCAPE '!'", [$searchPattern])
                        ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$searchPattern]);
                });
            }

            if (isset($filters['status'])) {
                $query->where('status', (bool) $filters['status']);
            }

            if (isset($filters['destination_type'])) {
                $query->where('destination_type', (int) $filters['destination_type']);
            }

            $destinations = $query->orderByDesc('id')
                ->paginate((int) ($filters['per_page'] ?? 15))
                ->appends($filters);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve destinations. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => DestinationResource::collection($destinations),
            'pagination' => [
                'current_page' => $destinations->currentPage(),
                'last_page' => $destinations->lastPage(),
                'per_page' => $destinations->perPage(),
                'total' => $destinations->total(),
                'from' => $destinations->firstItem(),
                'to' => $destinations->lastItem(),
                'next_page_url' => $destinations->nextPageUrl(),
                'prev_page_url' => $destinations->previousPageUrl(),
            ],
            'message' => 'Destinations retrieved successfully.',
        ]);
    }

    public function store(StoreDestinationRequest $request): JsonResponse
    {
        try {
            $destination = DB::transaction(function () use ($request): Destination {
                $destination = new Destination($request->safe()->except('image'));
                $destination->uploadedImage = $request->file('image');
                $destination->save();

                return $destination->refresh();
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the destination. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new DestinationResource($destination),
            'pagination' => null,
            'message' => 'Destination created successfully.',
        ], 201);
    }

    public function show(Destination $destination): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => new DestinationResource($destination),
            'pagination' => null,
            'message' => 'Destination retrieved successfully.',
        ]);
    }

    public function update(UpdateDestinationRequest $request, Destination $destination): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $destination): void {
                $destination->fill($request->safe()->except('image'));
                $destination->uploadedImage = $request->file('image');
                $destination->save();
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the destination. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new DestinationResource($destination),
            'pagination' => null,
            'message' => 'Destination updated successfully.',
        ]);
    }

    public function destroy(Destination $destination): JsonResponse
    {
        try {
            DB::transaction(fn (): ?bool => $destination->delete());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the destination. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Destination deleted successfully.',
        ]);
    }
}

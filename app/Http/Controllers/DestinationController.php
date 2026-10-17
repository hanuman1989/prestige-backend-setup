<?php

namespace App\Http\Controllers;

use App\Http\Resources\DestinationResource;
use App\Models\Destination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;

class DestinationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $destinations = Destination::query()->where('status', true)->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 15)->appends($filters);
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

    public function show(Request $request, string $destination): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $destination = Destination::query()->where('status', true)->findOrFail($destination);
            $packages = $destination->packages()->published()->with([
                'destinations' => fn (BelongsToMany $destinations): BelongsToMany => $destinations->where('status', true),
                'images' => fn (HasMany $images): HasMany => $images->limit(1),
            ])->orderByDesc('packages.id')->paginate($filters['per_page'] ?? 15)->appends($filters);
            $destination->setRelation('packages', $packages->getCollection());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve the destination. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new DestinationResource($destination),
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
            'message' => 'Destination retrieved successfully.',
        ]);
    }

    public function getTopDestination(): JsonResponse
    {
        try {
            $destinations = Destination::query()->where('status', true)->where('is_most_popular', true)
                ->withCount(['packages' => fn (Builder $packages): Builder => $packages->published()])
                ->orderByDesc('id')->limit(20)->get();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve top destinations. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => DestinationResource::collection($destinations),
            'pagination' => null,
            'message' => 'Top destinations retrieved successfully.',
        ]);
    }
}

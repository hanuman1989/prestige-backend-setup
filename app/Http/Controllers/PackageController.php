<?php

namespace App\Http\Controllers;

use App\Http\Resources\PackageResource;
use App\Models\Package;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;

class PackageController extends Controller
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
            $query = Package::query()->published()->with([
                'destinations' => fn (BelongsToMany $destinations): BelongsToMany => $destinations->where('status', true),
                'images' => fn (HasMany $images): HasMany => $images->limit(1),
            ]);
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

            $packages = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 15)->appends($filters);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve packages. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => PackageResource::collection($packages),
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
            'message' => 'Packages retrieved successfully.',
        ]);
    }

    public function show(string $package): JsonResponse
    {
        try {
            $package = Package::query()->published()->with([
                'categories' => fn (BelongsToMany $categories): BelongsToMany => $categories->where('status', true),
                'destinations' => fn (BelongsToMany $destinations): BelongsToMany => $destinations->where('status', true),
                'itineraries',
                'images',
            ])->findOrFail($package);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve the package. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new PackageResource($package),
            'pagination' => null,
            'message' => 'Package retrieved successfully.',
        ]);
    }

    public function getSpecialPackages(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1'],
        ]);
        try {
            $packages = Package::query()->published()->where('is_special_package', true)
                ->with(['categories', 'destinations', 'images' => fn (HasMany $images): HasMany => $images->limit(1)])
                ->orderByDesc('id')->limit($filters['limit'] ?? 20)->get();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve special packages. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => PackageResource::collection($packages),
            'pagination' => null,
            'message' => 'Special packages retrieved successfully.',
        ]);
    }
}

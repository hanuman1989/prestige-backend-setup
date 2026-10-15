<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePackageBasicDetailsRequest;
use App\Http\Requests\Admin\SavePackageDetailsRequest;
use App\Http\Requests\Admin\SavePackageItinerariesRequest;
use App\Http\Requests\Admin\SavePackagePhotosRequest;
use App\Http\Requests\Admin\SavePackageRelationsRequest;
use App\Http\Resources\PackageResource;
use App\Models\Package;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;

class PackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->listing($request);
    }

    public function publicIndex(Request $request): JsonResponse
    {
        return $this->listing($request, true);
    }

    public function show(Package $package): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => new PackageResource($package->load(['categories', 'destinations', 'itineraries', 'images'])),
            'pagination' => null,
            'message' => 'Package retrieved successfully.',
        ]);
    }

    public function saveBasicDetails(SavePackageBasicDetailsRequest $request, ?Package $package = null): JsonResponse
    {
        $package ??= $request->has('package_id')
            ? Package::query()->findOrFail($request->integer('package_id'))
            : null;

        if ($package !== null && $request->has('package_id') && $request->integer('package_id') !== $package->id) {
            throw ValidationException::withMessages(['package_id' => 'The package ID must match the URL.']);
        }

        return $this->saveStep($package, 1, function (Package $package) use ($request): void {
            $package->fill($request->safe()->except(['package_id', 'current_step']));
        });
    }

    public function saveRelations(SavePackageRelationsRequest $request, Package $package): JsonResponse
    {
        return $this->saveStep($package, 2, function (Package $package) use ($request): void {
            $data = $request->validated();
            $package->categories()->sync($data['category_ids']);
            $package->destinations()->sync($data['destination_ids']);
        });
    }

    public function saveItineraries(SavePackageItinerariesRequest $request, Package $package): JsonResponse
    {
        return $this->saveStep($package, 3, function (Package $package) use ($request): void {
            $data = $request->validated();
            $deletedIds = $data['deleted_itinerary_ids'] ?? [];
            $this->rejectConflictingIds($data['itineraries'], $deletedIds, 'deleted_itinerary_ids');

            foreach ($deletedIds as $id) {
                $package->itineraries()->findOrFail($id)->delete();
            }

            foreach ($data['itineraries'] as $index => $item) {
                $itinerary = isset($item['id'])
                    ? $package->itineraries()->findOrFail($item['id'])
                    : $package->itineraries()->make();
                $itinerary->fill([
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'sort_order' => $item['sort_order'],
                ]);
                $itinerary->uploadedImage = $request->file("itineraries.$index.image");
                $itinerary->save();
            }
        });
    }

    public function saveDetails(SavePackageDetailsRequest $request, Package $package): JsonResponse
    {
        return $this->saveStep($package, 4, function (Package $package) use ($request): void {
            $package->fill($request->safe()->only([
                'inclusion', 'exclusion', 'accommodation_details', 'transportation', 'description',
            ]));
        });
    }

    public function savePhotos(SavePackagePhotosRequest $request, Package $package): JsonResponse
    {
        return $this->saveStep($package, 5, function (Package $package) use ($request): void {
            $data = $request->validated();
            $photos = $data['photos'] ?? [];
            $deletedIds = $data['deleted_image_ids'] ?? [];
            $this->rejectConflictingIds($photos, $deletedIds, 'deleted_image_ids');

            foreach ($deletedIds as $id) {
                $package->images()->findOrFail($id)->delete();
            }

            foreach ($photos as $index => $item) {
                $photo = isset($item['id'])
                    ? $package->images()->findOrFail($item['id'])
                    : $package->images()->make();
                $photo->sort_order = $item['sort_order'];
                $photo->uploadedImage = $request->file("photos.$index.image");
                $photo->save();
            }

            if (! $package->images()->exists()) {
                throw ValidationException::withMessages(['photos' => 'At least one package photo must remain.']);
            }
        });
    }

    public function destroy(Package $package): JsonResponse
    {
        try {
            DB::transaction(function () use ($package): void {
                Package::query()->lockForUpdate()->findOrFail($package->id)->delete();
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the package. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => null, 'pagination' => null,
            'message' => 'Package deleted successfully.',
        ]);
    }

    private function listing(Request $request, bool $publishedOnly = false): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = Package::query()->with(['destinations', 'images' => fn (HasMany $images): HasMany => $images->limit(1)]);

            if ($publishedOnly) {
                $query->published();
            }

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

    /**
     * Lock the parent before checking progress and changing any step data.
     *
     * @param  Closure(Package): void  $save
     */
    private function saveStep(?Package $package, int $step, Closure $save): JsonResponse
    {
        $creating = $package === null;

        try {
            $savedPackage = DB::transaction(function () use ($package, $step, $save): Package {
                $lockedPackage = $package === null
                    ? new Package
                    : Package::query()->lockForUpdate()->findOrFail($package->id);

                if (($lockedPackage->current_step ?? 1) < $step) {
                    throw ValidationException::withMessages([
                        'step' => 'Save the preceding steps before saving this step.',
                    ]);
                }

                $save($lockedPackage);
                $lockedPackage->current_step = max($lockedPackage->current_step ?? 1, $step + 1);
                $lockedPackage->save();

                return $lockedPackage->refresh()->load(['categories', 'destinations', 'itineraries', 'images']);
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to save the package step. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new PackageResource($savedPackage),
            'pagination' => null,
            'message' => "Package step $step saved successfully.",
        ], $creating ? 201 : 200);
    }

    /**
     * @param  array<array-key, array{id?: int|null, image?: mixed, sort_order: int, title?: string, description?: string}>  $items
     * @param  list<int>  $deletedIds
     */
    private function rejectConflictingIds(array $items, array $deletedIds, string $field): void
    {
        if (array_intersect(array_column($items, 'id'), $deletedIds) !== []) {
            throw ValidationException::withMessages([$field => 'An item cannot be saved and deleted in the same request.']);
        }
    }
}

<?php

namespace App\Http\Resources;

use App\Models\PackageImage;
use App\Models\PackageItinerary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'duration' => $this->duration,
            'tour_best_time' => $this->tour_best_time,
            'is_special_package' => $this->is_special_package,
            'status' => $this->status,
            'meta_title' => $this->meta_title,
            'meta_keyword' => $this->meta_keyword,
            'meta_description' => $this->meta_description,
            'inclusion' => $this->inclusion,
            'exclusion' => $this->exclusion,
            'accommodation_details' => $this->accommodation_details,
            'transportation' => $this->transportation,
            'description' => $this->description,
            'current_step' => $this->current_step,
            'is_complete' => $this->current_step === 6,
            'completed_steps' => $this->current_step > 1 ? range(1, $this->current_step - 1) : [],
            'cover_image_url' => $this->whenLoaded('images', fn (): ?string => $this->imageUrl($this->images->first()?->image)),
            'cities_covered' => $this->whenLoaded('destinations', fn (): array => $this->destinations->pluck('title')->all()),
            'category_ids' => $this->whenLoaded('categories', fn (): array => $this->categories->modelKeys()),
            'destination_ids' => $this->whenLoaded('destinations', fn (): array => $this->destinations->modelKeys()),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'destinations' => DestinationResource::collection($this->whenLoaded('destinations')),
            'itineraries' => $this->whenLoaded('itineraries', fn () => $this->itineraries->map(fn (PackageItinerary $item): array => [
                'id' => $item->id, 'title' => $item->title, 'description' => $item->description,
                'image' => $item->image, 'image_url' => $this->imageUrl($item->image), 'sort_order' => $item->sort_order,
                'created_at' => $item->created_at?->toISOString(), 'updated_at' => $item->updated_at?->toISOString(),
            ])),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn (PackageImage $item): array => [
                'id' => $item->id, 'image' => $item->image, 'image_url' => $this->imageUrl($item->image), 'sort_order' => $item->sort_order,
            ])),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        return $path === null ? null : Storage::disk(config('filesystems.package_images_disk'))->url($path);
    }
}

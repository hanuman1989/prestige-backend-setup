<?php

namespace App\Http\Resources;

use App\Models\Destination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'meta_title' => $this->meta_title,
            'meta_keyword' => $this->meta_keyword,
            'meta_description' => $this->meta_description,
            'image' => $this->image,
            'image_url' => $this->image_url,
            'banner' => $this->banner,
            'banner_url' => $this->banner_url,
            'description' => $this->description,
            'is_most_popular' => $this->is_most_popular,
            'status' => $this->status,
            'count_packages' => $this->whenCounted('packages'),
            'destinations' => $this->whenLoaded('destinations', fn (): array => $this->destinations
                ->map(fn (Destination $destination): array => [
                    'slug' => $destination->slug,
                    'title' => $destination->title,
                ])->all()),
            'packages' => PackageResource::collection($this->whenLoaded('packages')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

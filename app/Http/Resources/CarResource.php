<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'car_category_id' => $this->car_category_id,
            'brand' => $this->brand,
            'model' => $this->model,
            'passenger_seats' => $this->passenger_seats,
            'luggage_bags' => $this->luggage_bags,
            'transmission' => $this->transmission,
            'has_ac' => $this->has_ac,
            'rate_basis' => $this->rate_basis,
            'starting_rate' => $this->starting_rate,
            'minimum_km_per_day' => $this->minimum_km_per_day,
            'driver_allowance_per_day' => $this->driver_allowance_per_day,
            'extra_km_rate' => $this->extra_km_rate,
            'inclusion' => $this->inclusion,
            'exclusion' => $this->exclusion,
            'cancellation_policy' => $this->cancellation_policy,
            'is_featured' => $this->is_featured,
            'car_category' => new CarCategoryResource($this->whenLoaded('carCategory')),
            'title' => $this->title,
            'slug' => $this->slug,
            'meta_title' => $this->meta_title,
            'meta_keyword' => $this->meta_keyword,
            'meta_description' => $this->meta_description,
            'image' => $this->image,
            'image_url' => $this->image_url,
            'description' => $this->description,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

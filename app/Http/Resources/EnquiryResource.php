<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnquiryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'destination_id' => $this->destination_id,
            'package_id' => $this->package_id,
            'car_id' => $this->car_id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'subject' => $this->subject,
            'message' => $this->message,
            'travel_date' => $this->travel_date?->toDateString(),
            'adults' => $this->adults,
            'children' => $this->children,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

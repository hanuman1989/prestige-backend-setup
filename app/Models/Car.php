<?php

namespace App\Models;

use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Car extends Model
{
    /** @use HasFactory<CarFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'car_category_id',
        'title',
        'slug',
        'brand',
        'model',
        'description',
        'passenger_seats',
        'luggage_bags',
        'transmission',
        'has_ac',
        'rate_basis',
        'starting_rate',
        'minimum_km_per_day',
        'driver_allowance_per_day',
        'extra_km_rate',
        'inclusion',
        'exclusion',
        'cancellation_policy',
        'is_featured',
        'status',
        'meta_title',
        'meta_keyword',
        'meta_description',
    ];

    /** @var list<string> */
    protected $appends = ['image_url'];

    /**
     * A transient upload consumed by the observer inside a database transaction.
     */
    public ?UploadedFile $uploadedImage = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'car_category_id' => 'integer',
            'passenger_seats' => 'integer',
            'luggage_bags' => 'integer',
            'has_ac' => 'boolean',
            'starting_rate' => 'decimal:2',
            'minimum_km_per_day' => 'integer',
            'driver_allowance_per_day' => 'decimal:2',
            'extra_km_rate' => 'decimal:2',
            'is_featured' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->image === null
                ? null
                : Storage::disk(config('filesystems.car_images_disk'))->url($this->image),
        );
    }

    public function carCategory(): BelongsTo
    {
        return $this->belongsTo(CarCategory::class);
    }
}

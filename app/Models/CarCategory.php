<?php

namespace App\Models;

use Database\Factories\CarCategoryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CarCategory extends Model
{
    /** @use HasFactory<CarCategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'slug',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'description',
        'is_recommended',
        'status',
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
            'is_recommended' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->image === null
                ? null
                : Storage::disk(config('filesystems.car_category_images_disk'))->url($this->image),
        );
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }
}

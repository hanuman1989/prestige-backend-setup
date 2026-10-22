<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'slug',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'description',
        'is_most_popular',
        'status',
    ];

    /** @var list<string> */
    protected $appends = ['image_url', 'banner_url'];

    /**
     * A transient upload consumed by the observer inside a database transaction.
     */
    public ?UploadedFile $uploadedImage = null;

    /**
     * A transient banner upload consumed by the observer inside a database transaction.
     */
    public ?UploadedFile $uploadedBanner = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_most_popular' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->image === null
                ? null
                : Storage::disk(config('filesystems.category_images_disk'))->url($this->image),
        );
    }

    protected function bannerUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->banner === null
                ? null
                : Storage::disk(config('filesystems.category_images_disk'))->url($this->banner),
        );
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'category_package');
    }
}

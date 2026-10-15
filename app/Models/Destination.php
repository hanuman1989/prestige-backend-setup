<?php

namespace App\Models;

use Database\Factories\DestinationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Destination extends Model
{
    /** @use HasFactory<DestinationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'slug',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'description',
        'destination_type',
        'is_most_popular',
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
            'destination_type' => 'integer',
            'is_most_popular' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->image === null
                ? null
                : Storage::disk(config('filesystems.destination_images_disk'))->url($this->image),
        );
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'destination_package');
    }
}

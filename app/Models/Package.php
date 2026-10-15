<?php

namespace App\Models;

use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title', 'slug', 'meta_title', 'meta_keyword', 'meta_description',
        'duration', 'tour_best_time', 'inclusion', 'exclusion',
        'accommodation_details', 'transportation', 'description',
        'is_special_package', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => 'boolean', 'is_special_package' => 'boolean', 'current_step' => 'integer'];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_package')->orderBy('categories.id');
    }

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Destination::class, 'destination_package')->orderBy('destinations.id');
    }

    public function itineraries(): HasMany
    {
        return $this->hasMany(PackageItinerary::class)->orderBy('sort_order')->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PackageImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', true)->where('current_step', 6);
    }
}

<?php

namespace App\Models;

use Database\Factories\PackageItineraryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

class PackageItinerary extends Model
{
    /** @use HasFactory<PackageItineraryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['title', 'description', 'sort_order'];

    public ?UploadedFile $uploadedImage = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\EnquiryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enquiry extends Model
{
    /** @use HasFactory<EnquiryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'source', 'destination_id', 'package_id', 'car_id', 'name', 'email', 'mobile',
        'subject', 'message', 'travel_date', 'adults', 'children',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'destination_id' => 'integer',
            'package_id' => 'integer',
            'car_id' => 'integer',
            'travel_date' => 'date',
            'adults' => 'integer',
            'children' => 'integer',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}

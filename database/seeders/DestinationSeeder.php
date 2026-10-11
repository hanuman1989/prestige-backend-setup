<?php

namespace Database\Seeders;

use App\Models\Destination;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DestinationSeeder extends Seeder
{
    public function run(): void
    {
        $destinations = [
            ['title' => 'Jaipur', 'destination_type' => 1],
            ['title' => 'Goa', 'destination_type' => 1],
            ['title' => 'Udaipur', 'destination_type' => 1],
            ['title' => 'Bali', 'destination_type' => 2],
            ['title' => 'Dubai', 'destination_type' => 2],
            ['title' => 'Paris', 'destination_type' => 2],
        ];

        foreach ($destinations as $destination) {
            Destination::firstOrCreate(['slug' => Str::slug($destination['title'])], $destination);
        }
    }
}

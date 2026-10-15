<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        Package::firstOrCreate(['slug' => 'royal-rajasthan-escape'], [
            'title' => 'Royal Rajasthan Escape', 'duration' => '6 Nights 7 Days',
            'tour_best_time' => 'October to March', 'status' => false,
        ]);
    }
}

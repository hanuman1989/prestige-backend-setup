<?php

namespace Database\Seeders;

use App\Models\CarCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CarCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Hatchback', 'Sedan', 'SUV', 'Luxury', 'Tempo Traveller'] as $title) {
            CarCategory::firstOrCreate(['slug' => Str::slug($title)], ['title' => $title]);
        }
    }
}

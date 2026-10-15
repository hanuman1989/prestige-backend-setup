<?php

namespace Database\Seeders;

use App\Models\Car;
use App\Models\CarCategory;
use Illuminate\Database\Seeder;

class CarSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(CarCategorySeeder::class);

        $carCategory = CarCategory::query()->where('slug', 'suv')->firstOrFail();

        Car::firstOrCreate(['slug' => 'toyota-innova-crysta'], [
            'car_category_id' => $carCategory->id,
            'title' => 'Toyota Innova Crysta',
            'brand' => 'Toyota',
            'model' => 'Innova Crysta',
            'passenger_seats' => 6,
            'luggage_bags' => 3,
            'transmission' => 'manual',
            'has_ac' => true,
            'rate_basis' => 'per_km',
            'starting_rate' => '18.50',
        ]);
    }
}

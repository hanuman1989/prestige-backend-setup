<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageItinerary;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PackageItinerary> */
class PackageItineraryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'image' => null,
            'sort_order' => 1,
        ];
    }
}

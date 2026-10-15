<?php

namespace Database\Factories;

use App\Models\Car;
use App\Models\CarCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Car> */
class CarFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'car_category_id' => CarCategory::factory(),
            'brand' => 'Toyota',
            'model' => 'Innova',
            'passenger_seats' => 6,
            'luggage_bags' => 2,
            'transmission' => 'manual',
            'has_ac' => true,
            'rate_basis' => 'per_km',
            'starting_rate' => '18.50',
            'minimum_km_per_day' => null,
            'driver_allowance_per_day' => null,
            'extra_km_rate' => null,
            'inclusion' => null,
            'exclusion' => null,
            'cancellation_policy' => null,
            'is_featured' => false,
            'title' => $title,
            'slug' => Str::slug($title),
            'meta_title' => null,
            'meta_keyword' => null,
            'meta_description' => null,
            'image' => null,
            'description' => fake()->sentence(),
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => false]);
    }
}

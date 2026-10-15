<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Package> */
class PackageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3), 'slug' => fake()->unique()->slug(),
            'duration' => '6 Nights 7 Days', 'tour_best_time' => 'October to March',
            'status' => true, 'is_special_package' => false, 'current_step' => 1,
        ];
    }

    public function atStep(int $step): static
    {
        return $this->state(fn (): array => ['current_step' => $step]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }
}

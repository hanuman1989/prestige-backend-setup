<?php

namespace Database\Factories;

use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Destination> */
class DestinationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'meta_title' => null,
            'meta_keyword' => null,
            'meta_description' => null,
            'image' => null,
            'description' => fake()->sentence(),
            'destination_type' => 1,
            'is_most_popular' => false,
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => false]);
    }

    public function international(): static
    {
        return $this->state(fn (array $attributes): array => ['destination_type' => 2]);
    }

    public function popular(): static
    {
        return $this->state(fn (array $attributes): array => ['is_most_popular' => true]);
    }
}

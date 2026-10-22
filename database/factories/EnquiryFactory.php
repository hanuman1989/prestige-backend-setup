<?php

namespace Database\Factories;

use App\Models\Enquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enquiry>
 */
class EnquiryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'contact',
            'destination_id' => null,
            'package_id' => null,
            'car_id' => null,
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'mobile' => fake()->numerify('##########'),
            'subject' => fake()->sentence(),
            'message' => fake()->paragraph(),
            'travel_date' => null,
            'adults' => null,
            'children' => null,
        ];
    }
}

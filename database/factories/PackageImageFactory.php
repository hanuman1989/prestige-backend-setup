<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PackageImage> */
class PackageImageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'image' => 'packages/photos/'.fake()->uuid().'.jpg',
            'sort_order' => 1,
        ];
    }
}

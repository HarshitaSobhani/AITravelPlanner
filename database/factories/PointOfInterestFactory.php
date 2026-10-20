<?php

namespace Database\Factories;

use App\Models\Destination;
use App\Models\PointOfInterest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PointOfInterest> */
class PointOfInterestFactory extends Factory
{
    protected $model = PointOfInterest::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'destination_id' => Destination::factory(),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => ucwords($name),
            'category' => 'landmark',
            'interests' => ['history'],
            'description' => 'Test place.',
            'latitude' => 48.8566 + fake()->randomFloat(4, -0.02, 0.02),
            'longitude' => 2.3522 + fake()->randomFloat(4, -0.02, 0.02),
            'suggested_duration_minutes' => 60,
            'popularity' => fake()->numberBetween(30, 90),
        ];
    }
}

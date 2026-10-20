<?php

namespace Database\Factories;

use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Destination> */
class DestinationFactory extends Factory
{
    protected $model = Destination::class;

    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => $name,
            'type' => 'city',
            'country_code' => 'FR',
            'country_name' => 'Testland',
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'timezone' => 'Europe/Paris',
            'currency_code' => 'EUR',
            'summary' => 'Test destination.',
        ];
    }
}

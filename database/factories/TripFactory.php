<?php

namespace Database\Factories;

use App\Models\Destination;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Trip> */
class TripFactory extends Factory
{
    protected $model = Trip::class;

    public function definition(): array
    {
        $start = now()->addDays(30)->startOfDay();

        return [
            'destination_id' => Destination::factory(),
            'title' => 'Trip '.fake()->word(),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
            'travelers' => 2,
            'currency' => 'EUR',
            'budget_minor' => 200000,
            'pace' => 'balanced',
        ];
    }

    public function configure(): static
    {
        return $this->state(fn () => [])->afterMaking(function (Trip $trip) {
            if (! $trip->user_id) {
                $trip->user_id = User::factory()->create()->id;
            }
        });
    }

    public function ownedBy(User $user): static
    {
        return $this->afterMaking(fn (Trip $t) => $t->user_id = $user->id);
    }
}

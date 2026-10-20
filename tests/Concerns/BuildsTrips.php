<?php

namespace Tests\Concerns;

use App\Models\Destination;
use App\Models\PointOfInterest;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;

trait BuildsTrips
{
    protected function seedCategories(): void
    {
        $this->seed(ExpenseCategorySeeder::class);
    }

    /** A destination with $n places spread around a centre, interests rotating. */
    protected function destinationWithPlaces(int $n = 12): Destination
    {
        $d = Destination::factory()->create(['slug' => 'testville-'.uniqid(), 'name' => 'Testville']);
        $interests = [['history'], ['food'], ['museums'], ['nature'], ['architecture', 'history']];
        for ($i = 0; $i < $n; $i++) {
            PointOfInterest::factory()->create([
                'destination_id' => $d->id,
                'name' => "Place $i",
                'slug' => "place-$i",
                'interests' => $interests[$i % count($interests)],
                'latitude' => 48.85 + ($i % 4) * 0.01,
                'longitude' => 2.30 + intdiv($i, 4) * 0.01,
                'popularity' => 90 - $i,
                'suggested_duration_minutes' => 60,
            ]);
        }

        return $d;
    }

    protected function payload(Destination $d, array $over = []): array
    {
        return array_merge([
            'destination_id' => $d->id,
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(22)->toDateString(),
            'travelers' => 2,
            'currency' => 'EUR',
            'budget' => '1500.50',
            'pace' => 'balanced',
            'interests' => ['history', 'food'],
        ], $over);
    }

    protected function createTripVia(User $u, Destination $d, array $over = []): Trip
    {
        $this->seedCategories();
        $this->actingAs($u)->post('/trips', $this->payload($d, $over))->assertSessionHasNoErrors();

        return Trip::where('user_id', $u->id)->latest('id')->firstOrFail();
    }
}

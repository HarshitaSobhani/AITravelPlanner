<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

class TripCreationTest extends TestCase
{
    use BuildsTrips, RefreshDatabase;

    public function test_guest_cannot_create_a_trip(): void
    {
        $d = $this->destinationWithPlaces(3);
        $this->post('/trips', $this->payload($d))->assertRedirect('/login');
        $this->assertDatabaseCount('trips', 0);
    }

    public function test_creates_trip_with_days_preferences_and_stops(): void
    {
        $user = User::factory()->create();
        $d = $this->destinationWithPlaces(12);
        $trip = $this->createTripVia($user, $d);

        $this->assertSame($user->id, $trip->user_id);
        $this->assertSame(3, $trip->days()->count());
        $this->assertSame(150050, $trip->budget_minor);
        $this->assertSame(['history', 'food'], $trip->preferences->interests);
        $this->assertSame(9, $trip->stops()->count()); // 3 days x 3 stops (balanced)
        $this->assertSame('Trip to Testville', $trip->title);
        $this->assertNotNull($trip->generated_at);
    }

    public function test_user_id_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $d = $this->destinationWithPlaces(3);
        $this->seedCategories();
        $this->actingAs($user)->post('/trips', $this->payload($d, ['user_id' => $other->id]));
        $this->assertSame($user->id, Trip::first()->user_id);
    }

    #[DataProvider('invalidInputs')]
    public function test_validation_rejects_bad_input(array $override, string $field): void
    {
        $user = User::factory()->create();
        $d = $this->destinationWithPlaces(3);
        $this->actingAs($user)->post('/trips', $this->payload($d, $override))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('trips', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'end before start' => [['start_date' => '2099-05-10', 'end_date' => '2099-05-09'], 'end_date'],
            'start in the past' => [['start_date' => '2001-01-01', 'end_date' => '2001-01-03'], 'start_date'],
            'too long' => [['start_date' => '2099-01-01', 'end_date' => '2099-03-01'], 'end_date'],
            'zero travellers' => [['travelers' => 0], 'travelers'],
            'too many travellers' => [['travelers' => 500], 'travelers'],
            'negative budget' => [['budget' => '-5'], 'budget'],
            'non numeric budget' => [['budget' => 'lots'], 'budget'],
            'unknown currency' => [['currency' => 'XXX'], 'currency'],
            'unknown pace' => [['pace' => 'frantic'], 'pace'],
            'unknown interest' => [['interests' => ['skydiving']], 'interests.0'],
            'missing destination' => [['destination_id' => 99999], 'destination_id'],
            'half a coordinate' => [['accommodation_latitude' => 10], 'accommodation_longitude'],
            'bad latitude' => [['accommodation_latitude' => 123, 'accommodation_longitude' => 5], 'accommodation_latitude'],
        ];
    }

    public function test_budget_and_interests_are_optional(): void
    {
        $user = User::factory()->create();
        $d = $this->destinationWithPlaces(6);
        $trip = $this->createTripVia($user, $d, ['budget' => null, 'interests' => []]);
        $this->assertNull($trip->budget_minor);
        $this->assertSame([], $trip->preferences->interests);
        $this->assertGreaterThan(0, $trip->stops()->count());
    }

    public function test_day_count_and_status_derive_from_dates(): void
    {
        $t = new Trip(['start_date' => '2030-05-01', 'end_date' => '2030-05-01']);
        $this->assertSame(1, $t->dayCount());
        $t = new Trip(['start_date' => '2030-05-01', 'end_date' => '2030-05-10']);
        $this->assertSame(10, $t->dayCount());
        $this->assertSame('upcoming', $t->status(CarbonImmutable::parse('2030-04-30')));
        $this->assertSame('in_progress', $t->status(CarbonImmutable::parse('2030-05-10')));
        $this->assertSame('completed', $t->status(CarbonImmutable::parse('2030-05-11')));
    }

    public function test_destination_without_places_creates_trip_with_explained_empty_plan(): void
    {
        $user = User::factory()->create();
        $d = $this->destinationWithPlaces(0);
        $trip = $this->createTripVia($user, $d);
        $this->assertSame(0, $trip->stops()->count());
        $this->assertSame('no_places', $trip->plan_warnings[0]['code']);
    }

    public function test_dashboard_lists_only_own_trips(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $d = $this->destinationWithPlaces(6);
        $this->createTripVia($a, $d);
        $this->createTripVia($b, $d);

        $this->actingAs($a)->get('/trips')->assertInertia(fn ($p) => $p->has('trips', 1)->where('trips.0.stops_count', 6));
    }
}

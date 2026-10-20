<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\TripShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use BuildsTrips, RefreshDatabase;

    public function test_every_trip_endpoint_hides_other_users_trips(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $d = $this->destinationWithPlaces(12);
        $trip = $this->createTripVia($owner, $d);
        $stop = $trip->stops()->first();
        $day = $trip->days()->first();
        $expense = Expense::factory()->create(['trip_id' => $trip->id]);
        $share = TripShare::issue($trip);
        $me = $this->actingAs($intruder);

        $me->get("/trips/{$trip->id}")->assertNotFound();
        $me->get("/trips/{$trip->id}/export")->assertNotFound();
        $me->get("/trips/{$trip->id}/weather")->assertNotFound();
        $me->get("/trips/{$trip->id}/days/{$day->id}/route")->assertNotFound();
        $me->patch("/trips/{$trip->id}", ['start_date' => '2099-01-01', 'end_date' => '2099-01-02', 'travelers' => 1, 'currency' => 'EUR', 'pace' => 'relaxed'])->assertNotFound();
        $me->delete("/trips/{$trip->id}")->assertNotFound();
        $me->post("/trips/{$trip->id}/regenerate")->assertNotFound();
        $me->post("/trips/{$trip->id}/summary")->assertNotFound();
        $me->post("/trips/{$trip->id}/stops", ['itinerary_day_id' => $day->id, 'name' => 'x'])->assertNotFound();
        $me->patch("/stops/{$stop->id}", ['notes' => 'hacked'])->assertNotFound();
        $me->delete("/stops/{$stop->id}")->assertNotFound();
        $me->post("/stops/{$stop->id}/move", ['itinerary_day_id' => $day->id, 'position' => 0])->assertNotFound();
        $me->patch("/days/{$day->id}", ['title' => 'hacked'])->assertNotFound();
        $me->post("/trips/{$trip->id}/expenses", ['expense_category_id' => 1, 'description' => 'x', 'currency' => 'EUR', 'estimated' => '1'])->assertNotFound();
        $me->patch("/expenses/{$expense->id}", ['expense_category_id' => 1, 'description' => 'x', 'currency' => 'EUR', 'estimated' => '1'])->assertNotFound();
        $me->delete("/expenses/{$expense->id}")->assertNotFound();
        $me->post("/trips/{$trip->id}/shares")->assertNotFound();
        $me->delete("/shares/{$share->id}")->assertNotFound();

        $this->assertNotSame('hacked', $stop->fresh()->notes);
        $this->assertDatabaseHas('trips', ['id' => $trip->id]);
        $this->assertNull($share->fresh()->revoked_at);
    }

    public function test_guests_cannot_reach_private_routes(): void
    {
        $this->get('/saved')->assertRedirect('/login');
        $this->post('/trips/1/regenerate')->assertRedirect('/login');
        $this->get('/lookup/destinations?q=paris')->assertRedirect('/login');
    }

    public function test_destination_discovery_is_public(): void
    {
        $this->destinationWithPlaces(2);
        $this->get('/destinations')->assertOk();
        $this->get('/')->assertOk();
    }
}

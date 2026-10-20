<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\TripShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

class ShareAndExportTest extends TestCase
{
    use BuildsTrips, RefreshDatabase;

    private function ownerTrip(): array
    {
        $user = User::factory()->create(['name' => 'Private Person', 'email' => 'private@example.test']);
        $trip = $this->createTripVia($user, $this->destinationWithPlaces(8), ['notes' => 'SECRET TRIP NOTE', 'budget' => '1000']);
        $trip->stops()->first()->update(['notes' => 'SECRET STOP NOTE']);
        Expense::factory()->create(['trip_id' => $trip->id, 'description' => 'SECRET EXPENSE', 'estimated_minor' => 12345]);

        return [$user, $trip];
    }

    public function test_owner_creates_share_and_public_can_view_read_only(): void
    {
        [$user, $trip] = $this->ownerTrip();
        $this->actingAs($user)->post("/trips/{$trip->id}/shares")->assertSessionHas('success');
        $share = TripShare::firstOrFail();
        $token = $share->token_encrypted;

        $this->assertSame(48, strlen($token));
        $this->assertNotSame($token, $share->getRawOriginal('token_encrypted'), 'token is encrypted at rest');
        $this->assertSame(hash('sha256', $token), $share->token_hash);

        auth()->logout();
        $res = $this->get("/s/{$token}")->assertOk();
        $res->assertInertia(fn ($p) => $p->component('Shared/Show')->where('trip.title', $trip->title)->has('days', 3));
        $body = json_encode($res->viewData('page')['props']);
        foreach (['SECRET TRIP NOTE', 'SECRET STOP NOTE', 'SECRET EXPENSE', 'private@example.test', 'Private Person'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertNull($res->viewData('page')['props']['budget']);
    }

    public function test_budget_totals_only_when_owner_opted_in(): void
    {
        [$user, $trip] = $this->ownerTrip();
        $this->actingAs($user)->post("/trips/{$trip->id}/shares", ['include_budget_totals' => true]);
        $token = TripShare::first()->token_encrypted;
        $props = $this->get("/s/{$token}")->viewData('page')['props'];
        $this->assertSame(12345, $props['budget']['estimated_minor']);
        $this->assertArrayNotHasKey('categories', $props['budget']);
        $this->assertStringNotContainsString('SECRET EXPENSE', json_encode($props));
    }

    public function test_revoked_unknown_and_malformed_tokens_all_404(): void
    {
        [$user, $trip] = $this->ownerTrip();
        $this->actingAs($user)->post("/trips/{$trip->id}/shares");
        $share = TripShare::first();
        $token = $share->token_encrypted;
        $this->get("/s/{$token}")->assertOk();

        $this->actingAs($user)->delete("/shares/{$share->id}")->assertSessionHas('success');
        $this->get("/s/{$token}")->assertNotFound();
        $this->get('/s/'.str_repeat('a', 48))->assertNotFound();
        $this->get('/s/short')->assertNotFound();
        $this->get('/s/'.rawurlencode("' OR 1=1 --"))->assertNotFound();
    }

    public function test_deleting_trip_invalidates_share(): void
    {
        [$user, $trip] = $this->ownerTrip();
        $this->actingAs($user)->post("/trips/{$trip->id}/shares");
        $token = TripShare::first()->token_encrypted;
        $this->actingAs($user)->delete("/trips/{$trip->id}");
        $this->get("/s/{$token}")->assertNotFound();
    }

    public function test_shared_page_is_not_indexable(): void
    {
        [$user, $trip] = $this->ownerTrip();
        $this->actingAs($user)->post("/trips/{$trip->id}/shares");
        $this->get('/s/'.TripShare::first()->token_encrypted)->assertSee('noindex', false);
    }

    public function test_tokens_are_unique_and_long(): void
    {
        [, $trip] = $this->ownerTrip();
        $tokens = collect(range(1, 20))->map(fn () => TripShare::issue($trip)->token_encrypted);
        $this->assertSame(20, $tokens->unique()->count());
        $this->assertTrue($tokens->every(fn ($t) => strlen($t) === 48));
    }

    public function test_export_reflects_saved_data_not_stale_state(): void
    {
        [$user, $trip] = $this->ownerTrip();
        $stop = $trip->stops()->orderBy('id')->first();
        $this->actingAs($user)->patch("/stops/{$stop->id}", ['notes' => 'Updated after first load', 'duration_minutes' => 145]);
        $trip->update(['title' => 'Renamed trip']);

        $res = $this->actingAs($user)->get("/trips/{$trip->id}/export")->assertOk();
        $res->assertSee('Renamed trip')
            ->assertSee($stop->name)
            ->assertSee('Updated after first load')
            ->assertSee('145 min')
            ->assertSee('SECRET TRIP NOTE')
            ->assertSee('Day 1')->assertSee('Day 3')
            ->assertSee('Budget')
            ->assertSee('123.45')
            ->assertSee('Nothing in this document is a booking or confirmation');
    }

    public function test_export_can_hide_notes_and_budget(): void
    {
        [$user, $trip] = $this->ownerTrip();
        $this->actingAs($user)->get("/trips/{$trip->id}/export?notes=0&budget=0")
            ->assertOk()->assertDontSee('SECRET TRIP NOTE')->assertDontSee('SECRET STOP NOTE')->assertDontSee('Remaining vs. estimates');
    }

    public function test_export_escapes_user_content(): void
    {
        [$user, $trip] = $this->ownerTrip();
        $trip->update(['title' => '<script>alert(1)</script>']);
        $this->actingAs($user)->get("/trips/{$trip->id}/export")->assertDontSee('<script>alert(1)</script>', false);
    }
}

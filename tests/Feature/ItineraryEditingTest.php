<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

class ItineraryEditingTest extends TestCase
{
    use BuildsTrips, RefreshDatabase;

    private function setup2(): array
    {
        $user = User::factory()->create();
        $d = $this->destinationWithPlaces(20);
        $trip = $this->createTripVia($user, $d);

        return [$user, $d, $trip];
    }

    public function test_remove_stop_renumbers_positions(): void
    {
        [$user, , $trip] = $this->setup2();
        $day = $trip->days()->first();
        $first = $day->stops()->first();
        $this->actingAs($user)->delete("/stops/{$first->id}")->assertSessionHas('success');
        $this->assertSame([0, 1], $day->stops()->pluck('position')->all());
    }

    public function test_reorder_within_day_is_persisted_explicitly(): void
    {
        [$user, , $trip] = $this->setup2();
        $day = $trip->days()->first();
        $ids = $day->stops()->pluck('id')->all();
        $this->actingAs($user)->post("/stops/{$ids[2]}/move", ['itinerary_day_id' => $day->id, 'position' => 0])->assertSessionHasNoErrors();
        $this->assertSame([$ids[2], $ids[0], $ids[1]], $day->stops()->pluck('id')->all());
        $this->assertSame([0, 1, 2], $day->stops()->pluck('position')->all());
    }

    public function test_move_between_days(): void
    {
        [$user, , $trip] = $this->setup2();
        [$d1, $d2] = [$trip->days[0], $trip->days[1]];
        $stop = $d1->stops()->first();
        $this->actingAs($user)->post("/stops/{$stop->id}/move", ['itinerary_day_id' => $d2->id, 'position' => 1]);
        $this->assertSame(2, $d1->stops()->count());
        $this->assertSame(4, $d2->stops()->count());
        $this->assertSame($stop->id, $d2->stops()->get()[1]->id);
        $this->assertSame([0, 1], $d1->stops()->pluck('position')->all());
        $this->assertTrue($stop->fresh()->is_locked);
    }

    public function test_cannot_move_stop_to_another_trips_day(): void
    {
        [$user, $d, $trip] = $this->setup2();
        $other = $this->createTripVia($user, $d);
        $stop = $trip->days[0]->stops()->first();
        $foreignDay = $other->days[0];
        $this->actingAs($user)->post("/stops/{$stop->id}/move", ['itinerary_day_id' => $foreignDay->id, 'position' => 0])->assertNotFound();
        $this->assertSame($trip->days[0]->id, $stop->fresh()->itinerary_day_id);
    }

    public function test_edit_duration_and_notes_and_lock(): void
    {
        [$user, , $trip] = $this->setup2();
        $stop = $trip->days[0]->stops()->first();
        $this->actingAs($user)->patch("/stops/{$stop->id}", ['duration_minutes' => 150, 'notes' => 'Book ahead', 'start_time' => '10:15'])->assertSessionHasNoErrors();
        $fresh = $stop->fresh();
        $this->assertSame(150, $fresh->duration_minutes);
        $this->assertSame('Book ahead', $fresh->notes);
        $this->assertTrue($fresh->is_locked);
        $this->assertTrue($fresh->time_locked);
        $this->assertSame('10:15', substr($fresh->start_time, 0, 5));
    }

    public function test_following_stops_are_retimed_after_a_duration_change(): void
    {
        [$user, , $trip] = $this->setup2();
        $day = $trip->days[0];
        $stops = $day->stops()->get();
        $before = $stops[1]->start_time;
        $this->actingAs($user)->patch("/stops/{$stops[0]->id}", ['duration_minutes' => 300]);
        $this->assertNotSame($before, $stops[1]->fresh()->start_time);
    }

    public function test_add_catalog_place_and_custom_stop(): void
    {
        [$user, $d, $trip] = $this->setup2();
        $day = $trip->days[0];
        $unused = $d->places()->whereNotIn('id', $trip->stops()->pluck('point_of_interest_id'))->first();

        $this->actingAs($user)->post("/trips/{$trip->id}/stops", ['itinerary_day_id' => $day->id, 'point_of_interest_id' => $unused->id])->assertSessionHas('success');
        $added = $day->stops()->where('point_of_interest_id', $unused->id)->first();
        $this->assertSame('manual', $added->source);
        $this->assertSame($unused->latitude, $added->latitude);

        // The same place cannot be added twice.
        $this->actingAs($user)->post("/trips/{$trip->id}/stops", ['itinerary_day_id' => $day->id, 'point_of_interest_id' => $unused->id])->assertSessionHas('error');
        $this->assertSame(1, $trip->stops()->where('point_of_interest_id', $unused->id)->count());

        $this->actingAs($user)->post("/trips/{$trip->id}/stops", ['itinerary_day_id' => $day->id, 'name' => 'Lunch with Sam'])->assertSessionHasNoErrors();
        $custom = $day->stops()->where('name', 'Lunch with Sam')->first();
        $this->assertNull($custom->latitude);
        $this->assertNull($custom->point_of_interest_id);
    }

    public function test_cannot_add_a_place_from_another_destination(): void
    {
        [$user, , $trip] = $this->setup2();
        $foreign = $this->destinationWithPlaces(2)->places()->first();
        $this->actingAs($user)->post("/trips/{$trip->id}/stops", ['itinerary_day_id' => $trip->days[0]->id, 'point_of_interest_id' => $foreign->id])->assertNotFound();
    }

    public function test_regeneration_keeps_manual_edits_and_never_duplicates(): void
    {
        [$user, , $trip] = $this->setup2();
        $edited = $trip->days[0]->stops()->first();
        $this->actingAs($user)->patch("/stops/{$edited->id}", ['notes' => 'keep me']);
        $this->actingAs($user)->post("/trips/{$trip->id}/stops", ['itinerary_day_id' => $trip->days[1]->id, 'name' => 'My own stop']);
        $untouched = $trip->days[2]->stops()->first();

        $this->actingAs($user)->post("/trips/{$trip->id}/regenerate", ['replace' => true])->assertSessionHas('success');

        $this->assertTrue($trip->stops()->whereKey($edited->id)->exists(), 'edited stop survives');
        $this->assertSame('keep me', $edited->fresh()->notes);
        $this->assertTrue($trip->stops()->where('name', 'My own stop')->exists());
        $this->assertFalse($trip->stops()->whereKey($untouched->id)->exists(), 'untouched generated stop was replaced');

        $ids = $trip->stops()->whereNotNull('point_of_interest_id')->pluck('point_of_interest_id');
        $this->assertSame($ids->count(), $ids->unique()->count(), 'no duplicate places');
    }

    public function test_fill_mode_is_idempotent(): void
    {
        [$user, , $trip] = $this->setup2();
        $count = $trip->stops()->count();
        $this->actingAs($user)->post("/trips/{$trip->id}/regenerate");
        $this->actingAs($user)->post("/trips/{$trip->id}/regenerate");
        $this->assertSame($count, $trip->stops()->count());
    }

    public function test_repeated_saves_do_not_duplicate_stops(): void
    {
        [$user, , $trip] = $this->setup2();
        $count = $trip->stops()->count();
        $stop = $trip->days[0]->stops()->first();
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->patch("/stops/{$stop->id}", ['notes' => 'same']);
        }
        $this->assertSame($count, $trip->stops()->count());
    }

    public function test_shortening_trip_moves_stops_instead_of_deleting_them(): void
    {
        [$user, , $trip] = $this->setup2();
        $total = $trip->stops()->count();
        $this->actingAs($user)->patch("/trips/{$trip->id}", $this->updatePayload($trip, ['end_date' => $trip->start_date->toDateString()]))
            ->assertSessionHasNoErrors();
        $trip->refresh();
        $this->assertSame(1, $trip->days()->count());
        $this->assertSame($total, $trip->stops()->count());
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8], $trip->days[0]->stops()->pluck('position')->all());
    }

    public function test_changing_start_date_shifts_every_day_and_keeps_stops(): void
    {
        [$user, , $trip] = $this->setup2();
        $stopIds = $trip->stops()->pluck('id')->all();
        $newStart = now()->addDays(40)->toDateString();
        $newEnd = now()->addDays(42)->toDateString();
        $this->actingAs($user)->patch("/trips/{$trip->id}", $this->updatePayload($trip, ['start_date' => $newStart, 'end_date' => $newEnd]));
        $trip->refresh();
        $this->assertSame([$newStart, now()->addDays(41)->toDateString(), $newEnd], $trip->days->map(fn ($d) => $d->date->toDateString())->all());
        $this->assertEqualsCanonicalizing($stopIds, $trip->stops()->pluck('id')->all());
    }

    public function test_extending_trip_adds_empty_days(): void
    {
        [$user, , $trip] = $this->setup2();
        $this->actingAs($user)->patch("/trips/{$trip->id}", $this->updatePayload($trip, ['end_date' => $trip->start_date->copy()->addDays(4)->toDateString()]));
        $this->assertSame(5, $trip->days()->count());
        $this->assertSame(0, $trip->days()->where('position', 4)->first()->stops()->count());
    }

    public function test_deleting_trip_removes_children(): void
    {
        [$user, , $trip] = $this->setup2();
        $this->actingAs($user)->delete("/trips/{$trip->id}")->assertRedirect('/trips');
        $this->assertDatabaseCount('trips', 0);
        $this->assertDatabaseCount('itinerary_days', 0);
        $this->assertDatabaseCount('itinerary_stops', 0);
        $this->assertDatabaseCount('trip_preferences', 0);
    }

    private function updatePayload($trip, array $over = []): array
    {
        return array_merge([
            'start_date' => $trip->start_date->toDateString(),
            'end_date' => $trip->end_date->toDateString(),
            'travelers' => $trip->travelers,
            'currency' => $trip->currency,
            'pace' => $trip->pace,
            'interests' => ['history'],
        ], $over);
    }
}

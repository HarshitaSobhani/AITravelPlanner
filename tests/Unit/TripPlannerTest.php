<?php

namespace Tests\Unit;

use App\Services\Planning\TripPlanner;
use PHPUnit\Framework\TestCase;

class TripPlannerTest extends TestCase
{
    private function places(int $n = 14, array $over = []): array
    {
        $sets = [['history'], ['food'], ['museums'], ['nature'], ['architecture', 'history']];
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = array_merge([
                'id' => $i, 'name' => "Place $i",
                'latitude' => 48.85 + ($i % 4) * 0.01, 'longitude' => 2.30 + intdiv($i, 4) * 0.01,
                'interests' => $sets[$i % 5], 'popularity' => 100 - $i, 'duration' => 60,
            ], $over[$i] ?? []);
        }

        return $out;
    }

    private function dates(int $n): array
    {
        return array_map(fn ($i) => date('Y-m-d', strtotime("2030-05-06 +$i day")), range(0, $n - 1));
    }

    private function planFor(int $days, string $pace, array $interests, array $places): array
    {
        return (new TripPlanner)->plan(['dates' => $this->dates($days), 'pace' => $pace, 'interests' => $interests, 'places' => $places]);
    }

    public function test_one_plan_day_per_date_for_different_trip_lengths(): void
    {
        foreach ([1, 3, 7] as $n) {
            $plan = $this->planFor($n, 'relaxed', [], $this->places(30));
            $this->assertCount($n, $plan['days']);
            foreach ($plan['days'] as $stops) {
                $this->assertCount(2, $stops, "relaxed pace is 2 stops/day (trip of $n days)");
            }
        }
    }

    public function test_pace_controls_stop_count(): void
    {
        $places = $this->places(30);
        $this->assertCount(2, $this->planFor(1, 'relaxed', [], $places)['days'][0]);
        $this->assertCount(3, $this->planFor(1, 'balanced', [], $places)['days'][0]);
        $this->assertLessThanOrEqual(5, count($this->planFor(1, 'packed', [], $places)['days'][0]));
        $this->assertGreaterThan(3, count($this->planFor(1, 'packed', [], $places)['days'][0]));
    }

    public function test_interest_filter_prefers_matching_places(): void
    {
        $plan = $this->planFor(2, 'relaxed', ['food'], $this->places(20));
        $matching = collect($this->places(20))->filter(fn ($p) => in_array('food', $p['interests']))->pluck('id');
        foreach (collect($plan['days'])->flatten(1) as $stop) {
            $this->assertTrue($matching->contains($stop['place_id']), "{$stop['name']} should be a food place");
        }
    }

    public function test_backfills_and_warns_when_few_places_match(): void
    {
        $places = $this->places(10, [1 => ['interests' => ['nightlife']]]);
        $plan = $this->planFor(2, 'balanced', ['nightlife'], $places);
        $this->assertSame(6, collect($plan['days'])->flatten(1)->count());
        $this->assertContains('few_matches', array_column($plan['warnings'], 'code'));
    }

    public function test_no_duplicate_places_and_duplicate_entries_are_collapsed(): void
    {
        $places = $this->places(10);
        $places[] = ['id' => 99, 'name' => 'PLACE 1', 'latitude' => $places[0]['latitude'], 'longitude' => $places[0]['longitude'],
            'interests' => ['history'], 'popularity' => 1, 'duration' => 60]; // same name, same spot, lower rank
        $places[] = $places[2]; // exact repeated id
        $plan = $this->planFor(3, 'balanced', [], $places);
        $ids = collect($plan['days'])->flatten(1)->pluck('place_id');
        $this->assertSame($ids->count(), $ids->unique()->count());
        $this->assertNotContains(99, $ids->all());
    }

    public function test_excluded_places_are_never_planned(): void
    {
        $plan = (new TripPlanner)->plan(['dates' => $this->dates(2), 'pace' => 'balanced', 'places' => $this->places(10), 'exclude' => [1, 2, 3]]);
        $ids = collect($plan['days'])->flatten(1)->pluck('place_id');
        $this->assertEmpty($ids->intersect([1, 2, 3]));
    }

    public function test_places_without_valid_coordinates_are_skipped(): void
    {
        $places = $this->places(6, [1 => ['latitude' => null], 2 => ['latitude' => 200]]);
        $plan = $this->planFor(1, 'balanced', [], $places);
        $ids = collect($plan['days'])->flatten(1)->pluck('place_id');
        $this->assertNotContains(1, $ids->all());
        $this->assertNotContains(2, $ids->all());
    }

    public function test_deterministic(): void
    {
        $a = $this->planFor(4, 'balanced', ['history', 'food'], $this->places(25));
        $b = $this->planFor(4, 'balanced', ['history', 'food'], array_reverse($this->places(25)));
        $this->assertSame($a, $b);
    }

    public function test_times_are_ordered_and_inside_day_window(): void
    {
        $plan = $this->planFor(3, 'packed', [], $this->places(30));
        foreach ($plan['days'] as $stops) {
            $prevEnd = 0;
            foreach ($stops as $s) {
                [$h, $m] = explode(':', $s['start_time']);
                $start = $h * 60 + $m;
                $this->assertGreaterThanOrEqual(TripPlanner::DAY_START_MIN, $start);
                $this->assertGreaterThanOrEqual($prevEnd, $start);
                $this->assertLessThanOrEqual(TripPlanner::DAY_END_MIN, $start + $s['duration']);
                $prevEnd = $start + $s['duration'];
            }
        }
    }

    public function test_long_visits_that_do_not_fit_are_dropped_with_warning(): void
    {
        $places = $this->places(8, array_fill(1, 8, ['duration' => 400]));
        $plan = $this->planFor(1, 'packed', [], $places);
        $this->assertLessThan(5, count($plan['days'][0]));
        $this->assertContains('overflow', array_column($plan['warnings'], 'code'));
    }

    public function test_nearby_places_are_grouped_into_the_same_day(): void
    {
        $places = [];
        // two tight clusters ~5 km apart
        foreach ([[1, 48.850, 2.300], [2, 48.851, 2.301], [3, 48.852, 2.299], [4, 48.900, 2.360], [5, 48.901, 2.361], [6, 48.899, 2.359]] as [$id, $la, $lo]) {
            $places[] = ['id' => $id, 'name' => "P$id", 'latitude' => $la, 'longitude' => $lo, 'interests' => ['history'], 'popularity' => 80, 'duration' => 60];
        }
        $plan = $this->planFor(2, 'balanced', [], $places);
        $sets = collect($plan['days'])->map(fn ($d) => collect($d)->pluck('place_id')->sort()->values()->all())->sort()->values()->all();
        $this->assertSame([[1, 2, 3], [4, 5, 6]], $sets);
    }

    public function test_opening_hours_only_used_when_present(): void
    {
        $noHours = $this->planFor(1, 'balanced', [], $this->places(5));
        $this->assertContains('no_opening_hours', array_column($noHours['warnings'], 'code'));

        // 2030-05-06 is a Monday. Place 1 opens at 14:00 on Mondays -> start is pushed to 14:00.
        $hours = ['mon' => [['14:00', '18:00']]];
        $places = $this->places(1, [1 => ['opening_hours' => $hours]]);
        $plan = $this->planFor(1, 'relaxed', [], $places);
        $this->assertSame('14:00', $plan['days'][0][0]['start_time']);
    }

    public function test_closed_day_in_source_data_is_flagged_not_hidden(): void
    {
        $places = $this->places(1, [1 => ['opening_hours' => ['tue' => [['09:00', '17:00']]]]]); // Monday: no entry
        $plan = $this->planFor(1, 'relaxed', [], $places);
        $this->assertNotEmpty($plan['days'][0][0]['warnings']);
    }

    public function test_empty_catalog_returns_empty_days_and_explains(): void
    {
        $plan = $this->planFor(3, 'balanced', ['food'], []);
        $this->assertCount(3, $plan['days']);
        $this->assertSame([[], [], []], $plan['days']);
        $this->assertSame('no_places', $plan['warnings'][0]['code']);
    }

    public function test_few_places_warns_about_unfilled_slots(): void
    {
        $plan = $this->planFor(3, 'balanced', [], $this->places(4));
        $this->assertContains('few_places', array_column($plan['warnings'], 'code'));
        $this->assertSame(4, collect($plan['days'])->flatten(1)->count());
    }

    public function test_existing_stops_reduce_capacity(): void
    {
        $plan = (new TripPlanner)->plan([
            'dates' => $this->dates(1), 'pace' => 'balanced', 'places' => $this->places(10),
            'existing' => [0 => [['start_time' => '09:30', 'duration' => 60, 'latitude' => 48.85, 'longitude' => 2.3], ['start_time' => '11:00', 'duration' => 60]]],
        ]);
        $this->assertCount(1, $plan['days'][0]);
        $this->assertGreaterThanOrEqual('12:00', $plan['days'][0][0]['start_time']);
    }
}

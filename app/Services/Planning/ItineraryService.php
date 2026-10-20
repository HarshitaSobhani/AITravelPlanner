<?php

namespace App\Services\Planning;

use App\Models\ItineraryDay;
use App\Models\ItineraryStop;
use App\Models\PointOfInterest;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

/**
 * Persists and edits itineraries. All multi-row changes run in a transaction so a failure never
 * leaves a half-edited plan. User edits mark stops as locked; regeneration never removes them.
 */
class ItineraryService
{
    public function __construct(private readonly TripPlanner $planner) {}

    /**
     * Make the trip's days match its date range. Stops on removed days are moved to the last
     * remaining day, never deleted. Returns how many stops were relocated.
     */
    public function syncDays(Trip $trip): int
    {
        return DB::transaction(function () use ($trip) {
            $count = $trip->dayCount();
            $days = $trip->days()->get()->values();
            $moved = 0;

            for ($i = 0; $i < $count; $i++) {
                $date = $trip->start_date->copy()->addDays($i)->toDateString();
                $day = $days[$i] ?? new ItineraryDay(['position' => $i]);
                $day->date = $date;
                $day->position = $i;
                $day->trip_id = $trip->id;
                $day->save();
                $days[$i] = $day;
            }

            if ($days->count() > $count) {
                $target = $days[$count - 1];
                $next = $target->stops()->max('position') + 1;
                foreach ($days->slice($count) as $extra) {
                    foreach ($extra->stops()->get() as $stop) {
                        $stop->itinerary_day_id = $target->id;
                        $stop->position = $next++;
                        $stop->is_locked = true; // keep what the user had; do not let regeneration remove it
                        $stop->save();
                        $moved++;
                    }
                    $extra->delete();
                }
                $this->renumber($target);
            }

            return $moved;
        });
    }

    /**
     * Generate suggestions. With $replaceUnlocked, previously generated stops the user has not touched
     * are replaced; locked and manual stops always stay. Safe to call repeatedly: no duplicate places.
     */
    public function generate(Trip $trip, bool $replaceUnlocked = false): array
    {
        return DB::transaction(function () use ($trip, $replaceUnlocked) {
            $trip->loadMissing(['preferences', 'destination']);
            $this->syncDays($trip);

            if ($replaceUnlocked) {
                $trip->stops()->where('source', 'generated')->where('is_locked', false)->delete();
            }

            $days = $trip->days()->with('stops')->get()->values();
            $existing = [];
            $exclude = [];
            foreach ($days as $i => $day) {
                $existing[$i] = $day->stops->map(fn ($s) => [
                    'place_id' => $s->point_of_interest_id,
                    'latitude' => $s->latitude,
                    'longitude' => $s->longitude,
                    'start_time' => $s->start_time ? substr($s->start_time, 0, 5) : null,
                    'duration' => $s->duration_minutes,
                ])->all();
                foreach ($day->stops as $s) {
                    if ($s->point_of_interest_id) {
                        $exclude[] = (int) $s->point_of_interest_id;
                    }
                }
            }

            $prefs = $trip->preferences;
            $places = PointOfInterest::where('destination_id', $trip->destination_id)->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'latitude' => $p->latitude,
                'longitude' => $p->longitude,
                'interests' => $p->interests ?? [],
                'popularity' => $p->popularity,
                'duration' => $p->suggested_duration_minutes,
                'opening_hours' => $p->opening_hours,
            ])->all();

            $origin = $prefs && $prefs->accommodation_latitude !== null && $prefs->accommodation_longitude !== null
                ? [$prefs->accommodation_latitude, $prefs->accommodation_longitude] : null;

            $plan = $this->planner->plan([
                'dates' => $days->map(fn ($d) => $d->date->toDateString())->all(),
                'pace' => $trip->pace,
                'interests' => $prefs?->interests ?? [],
                'places' => $places,
                'existing' => $existing,
                'exclude' => $exclude,
                'origin' => $origin,
            ]);

            $created = 0;
            foreach ($days as $i => $day) {
                $position = (int) $day->stops()->max('position');
                $position = $day->stops()->exists() ? $position + 1 : 0;
                foreach ($plan['days'][$i] ?? [] as $s) {
                    $stop = new ItineraryStop([
                        'name' => $s['name'],
                        'latitude' => $s['latitude'],
                        'longitude' => $s['longitude'],
                        'start_time' => $s['start_time'],
                        'duration_minutes' => $s['duration'],
                        'position' => $position++,
                    ]);
                    $stop->trip_id = $trip->id;
                    $stop->itinerary_day_id = $day->id;
                    $stop->point_of_interest_id = $s['place_id'];
                    $stop->source = 'generated';
                    $stop->is_locked = false;
                    $stop->warnings = $s['warnings'] ?: null;
                    $stop->save();
                    $created++;
                }
            }

            $trip->plan_warnings = $plan['warnings'];
            $trip->generated_at = now();
            $trip->save();

            return ['created' => $created, 'warnings' => $plan['warnings']];
        });
    }

    public function addStop(Trip $trip, ItineraryDay $day, array $data): ItineraryStop
    {
        abort_unless($day->trip_id === $trip->id, 404);

        return DB::transaction(function () use ($trip, $day, $data) {
            $place = null;
            if (! empty($data['point_of_interest_id'])) {
                $place = PointOfInterest::where('destination_id', $trip->destination_id)->findOrFail($data['point_of_interest_id']);
            }
            $stop = new ItineraryStop([
                'name' => $place?->name ?? $data['name'],
                'latitude' => $place?->latitude ?? ($data['latitude'] ?? null),
                'longitude' => $place?->longitude ?? ($data['longitude'] ?? null),
                'duration_minutes' => $data['duration_minutes'] ?? $place?->suggested_duration_minutes ?? 60,
                'notes' => $data['notes'] ?? null,
                'position' => $day->stops()->count() ? $day->stops()->max('position') + 1 : 0,
            ]);
            $stop->trip_id = $trip->id;
            $stop->itinerary_day_id = $day->id;
            $stop->point_of_interest_id = $place?->id;
            $stop->source = 'manual';
            $stop->is_locked = true;
            if (! empty($data['start_time'])) {
                $stop->start_time = $data['start_time'];
                $stop->time_locked = true;
            }
            $stop->save();
            $this->rescheduleDay($day);

            return $stop->refresh();
        });
    }

    public function updateStop(ItineraryStop $stop, array $data): ItineraryStop
    {
        return DB::transaction(function () use ($stop, $data) {
            if (array_key_exists('duration_minutes', $data) && $data['duration_minutes'] !== null) {
                $stop->duration_minutes = $data['duration_minutes'];
            }
            if (array_key_exists('notes', $data)) {
                $stop->notes = $data['notes'];
            }
            if (array_key_exists('name', $data) && ! $stop->point_of_interest_id && $data['name']) {
                $stop->name = $data['name'];
            }
            if (array_key_exists('start_time', $data)) {
                $stop->start_time = $data['start_time'] ?: null;
                $stop->time_locked = (bool) $data['start_time'];
            }
            $stop->is_locked = true;
            $stop->save();
            $this->rescheduleDay($stop->day);

            return $stop;
        });
    }

    public function removeStop(ItineraryStop $stop): void
    {
        DB::transaction(function () use ($stop) {
            $day = $stop->day;
            $stop->delete();
            $this->renumber($day);
            $this->rescheduleDay($day);
        });
    }

    /** Move a stop to $targetDay at $position (0-based), shifting the others. */
    public function moveStop(ItineraryStop $stop, ItineraryDay $targetDay, int $position): void
    {
        abort_unless($targetDay->trip_id === $stop->trip_id, 404);

        DB::transaction(function () use ($stop, $targetDay, $position) {
            $sourceDay = $stop->day;
            $ids = $targetDay->stops()->where('id', '!=', $stop->id)->orderBy('position')->pluck('id')->all();
            $position = max(0, min($position, count($ids)));
            array_splice($ids, $position, 0, [$stop->id]);

            $stop->itinerary_day_id = $targetDay->id;
            $stop->is_locked = true;
            $stop->save();

            foreach ($ids as $i => $id) {
                ItineraryStop::where('id', $id)->update(['position' => $i]);
            }
            if ($sourceDay->id !== $targetDay->id) {
                $this->renumber($sourceDay);
                $this->rescheduleDay($sourceDay);
            }
            $this->rescheduleDay($targetDay);
        });
    }

    public function renumber(ItineraryDay $day): void
    {
        $day->stops()->orderBy('position')->orderBy('id')->pluck('id')->each(
            fn ($id, $i) => ItineraryStop::where('id', $id)->update(['position' => $i])
        );
    }

    /**
     * Recompute start times in order. Stops whose time the user set explicitly keep it; others follow the
     * previous stop plus a straight-line travel estimate.
     */
    public function rescheduleDay(ItineraryDay $day): void
    {
        $stops = $day->stops()->get();
        $cursor = TripPlanner::DAY_START_MIN;
        $prev = null;
        foreach ($stops as $stop) {
            if ($stop->time_locked && $stop->start_time) {
                [$h, $m] = array_map('intval', explode(':', $stop->start_time));
                $start = $h * 60 + $m;
            } else {
                $start = $cursor;
                if ($prev && $stop->latitude !== null && $prev->latitude !== null) {
                    $start += TripPlanner::travelMinutes(
                        ['latitude' => $prev->latitude, 'longitude' => $prev->longitude],
                        ['latitude' => $stop->latitude, 'longitude' => $stop->longitude]
                    );
                }
                $start = min($start, 23 * 60);
                $formatted = sprintf('%02d:%02d', intdiv($start, 60), $start % 60);
                if (substr((string) $stop->start_time, 0, 5) !== $formatted) {
                    $stop->start_time = $formatted;
                    $stop->save();
                }
            }
            $cursor = $start + $stop->duration_minutes;
            $prev = $stop;
        }
    }
}

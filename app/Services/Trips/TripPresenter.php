<?php

namespace App\Services\Trips;

use App\Models\Destination;
use App\Models\Trip;
use App\Support\Geo;
use App\Support\Money;

/** Builds the arrays sent to Vue and to the export view. The public variant omits everything private. */
class TripPresenter
{
    public function __construct(private readonly BudgetService $budget) {}

    public function destination(Destination $d): array
    {
        return [
            'id' => $d->id, 'slug' => $d->slug, 'name' => $d->name, 'type' => $d->type,
            'country_name' => $d->country_name, 'country_code' => $d->country_code,
            'latitude' => $d->latitude, 'longitude' => $d->longitude, 'timezone' => $d->timezone,
            'currency_code' => $d->currency_code, 'summary' => $d->summary, 'best_season' => $d->best_season,
            'image_url' => Geo::safeUrl($d->image_url), 'image_attribution' => $d->image_attribution,
        ];
    }

    public function owner(Trip $trip): array
    {
        $trip->load(['destination.dataSource', 'preferences', 'days.stops.place']);

        return [
            'trip' => $this->base($trip) + [
                'notes' => $trip->notes,
                'summary' => $trip->summary,
                'budget_minor' => $trip->budget_minor,
                'budget_input' => $trip->budget_minor === null ? '' : Money::toDecimalString($trip->budget_minor, $trip->currency),
                'preferences' => [
                    'interests' => $trip->preferences?->interests ?? [],
                    'accessibility' => $trip->preferences?->accessibility,
                    'dietary' => $trip->preferences?->dietary,
                    'accommodation' => $trip->preferences?->accommodation,
                    'accommodation_latitude' => $trip->preferences?->accommodation_latitude,
                    'accommodation_longitude' => $trip->preferences?->accommodation_longitude,
                ],
            ],
            'days' => $this->days($trip, true),
            'budget' => $this->budget->summarize($trip),
        ];
    }

    /** Public, read-only view: no trip notes, no stop notes, no user data, budget totals only if enabled. */
    public function shared(Trip $trip, bool $includeBudget): array
    {
        $trip->load(['destination.dataSource', 'days.stops.place']);
        $out = [
            'trip' => $this->base($trip),
            'days' => $this->days($trip, false),
            'budget' => null,
        ];
        if ($includeBudget) {
            $b = $this->budget->summarize($trip);
            $out['budget'] = [
                'currency' => $b['currency'], 'exponent' => $b['exponent'],
                'estimated_minor' => $b['estimated_minor'], 'budget_minor' => $b['budget_minor'],
            ];
        }

        return $out;
    }

    private function base(Trip $trip): array
    {
        return [
            'id' => $trip->id,
            'title' => $trip->title,
            'start_date' => $trip->start_date->toDateString(),
            'end_date' => $trip->end_date->toDateString(),
            'day_count' => $trip->dayCount(),
            'travelers' => $trip->travelers,
            'currency' => $trip->currency,
            'pace' => $trip->pace,
            'status' => $trip->status(),
            'warnings' => $trip->plan_warnings ?? [],
            'generated_at' => $trip->generated_at?->toIso8601String(),
            'updated_at' => $trip->updated_at->toIso8601String(),
            'destination' => $this->destination($trip->destination),
            'source_attribution' => $trip->destination->dataSource?->attribution,
        ];
    }

    private function days(Trip $trip, bool $private): array
    {
        return $trip->days->map(fn ($day) => [
            'id' => $day->id,
            'position' => $day->position,
            'date' => $day->date->toDateString(),
            'title' => $private ? $day->title : null,
            'notes' => $private ? $day->notes : null,
            'stops' => $day->stops->map(function ($s) use ($private) {
                $place = $s->place;

                return [
                    'id' => $s->id,
                    'position' => $s->position,
                    'name' => $s->name,
                    'latitude' => $s->latitude,
                    'longitude' => $s->longitude,
                    'start_time' => $s->start_time ? substr($s->start_time, 0, 5) : null,
                    'duration_minutes' => $s->duration_minutes,
                    'notes' => $private ? $s->notes : null,
                    'source' => $s->source,
                    'is_locked' => $s->is_locked,
                    'time_locked' => $s->time_locked,
                    'warnings' => $s->warnings ?? [],
                    'category' => $place?->category,
                    'place_id' => $place?->id,
                    'description' => $place?->description,
                    'neighbourhood' => $place?->neighbourhood,
                    'website' => Geo::safeUrl($place?->website),
                    'accessibility_note' => $place?->accessibility_note,
                    'verified' => $place?->coordinates_verified_at !== null,
                ];
            })->values()->all(),
        ])->values()->all();
    }
}

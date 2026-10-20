<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\PointOfInterest;
use App\Services\Planning\TripPlanner;
use App\Support\Interests;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(TripPlanner $planner): Response
    {
        $destinations = Destination::withCount('places')->orderBy('name')->limit(6)->get()->map(fn ($d) => [
            'slug' => $d->slug, 'name' => $d->name, 'country_name' => $d->country_name,
            'summary' => $d->summary, 'places_count' => $d->places_count,
        ]);

        return Inertia::render('Welcome', [
            'destinations' => $destinations,
            'interests' => collect(Interests::ALL)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'examples' => Cache::remember('landing-examples:v1', now()->addHour(), fn () => $this->examples($planner)),
        ]);
    }

    /** Real planner output for sample inputs, so the landing page never shows hand-written fake itineraries. */
    private function examples(TripPlanner $planner): array
    {
        $out = [];
        foreach ([['paris', ['museums', 'food']], ['kyoto', ['history', 'nature']], ['lisbon', ['food', 'architecture']]] as [$slug, $interests]) {
            $dest = Destination::where('slug', $slug)->first();
            if (! $dest) {
                continue;
            }
            $places = PointOfInterest::where('destination_id', $dest->id)->get()->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'latitude' => $p->latitude, 'longitude' => $p->longitude,
                'interests' => $p->interests, 'popularity' => $p->popularity, 'duration' => $p->suggested_duration_minutes,
                'opening_hours' => $p->opening_hours,
            ])->all();
            $plan = $planner->plan(['dates' => ['2000-01-03', '2000-01-04'], 'pace' => 'balanced', 'interests' => $interests, 'places' => $places]);
            $out[] = [
                'destination' => $dest->name, 'slug' => $dest->slug,
                'interests' => collect($interests)->map(fn ($i) => Interests::ALL[$i])->all(),
                'days' => collect($plan['days'])->map(fn ($stops) => collect($stops)->map(fn ($s) => ['name' => $s['name'], 'start_time' => $s['start_time']])->all())->all(),
            ];
        }

        return $out;
    }
}

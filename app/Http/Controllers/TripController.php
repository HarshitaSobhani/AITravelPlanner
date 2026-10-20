<?php

namespace App\Http\Controllers;

use App\Http\Requests\TripRequest;
use App\Models\Destination;
use App\Models\ExpenseCategory;
use App\Models\Trip;
use App\Services\Ai\ItineraryNarrator;
use App\Services\Planning\ItineraryService;
use App\Services\Routing\RouteService;
use App\Services\Trips\TripPresenter;
use App\Services\Weather\WeatherService;
use App\Support\Geo;
use App\Support\Interests;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class TripController extends Controller
{
    public function __construct(
        private readonly ItineraryService $itinerary,
        private readonly TripPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $trips = $request->user()->trips()
            ->with('destination:id,name,country_name,slug')
            ->withCount('stops')
            ->orderBy('start_date')
            ->get()
            ->map(fn (Trip $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'destination' => $t->destination->name.($t->destination->country_name ? ', '.$t->destination->country_name : ''),
                'start_date' => $t->start_date->toDateString(),
                'end_date' => $t->end_date->toDateString(),
                'status' => $t->status(),
                'stops_count' => $t->stops_count,
                'travelers' => $t->travelers,
                'currency' => $t->currency,
                'exponent' => Money::exponent($t->currency),
                'budget_minor' => $t->budget_minor,
                'updated_at' => $t->updated_at->toIso8601String(),
            ]);

        return Inertia::render('Trips/Index', ['trips' => $trips]);
    }

    public function create(Request $request): Response
    {
        $selected = null;
        if ($slug = $request->query('destination')) {
            $selected = Destination::where('slug', $slug)->first();
        }

        return Inertia::render('Trips/Create', [
            'destinations' => Destination::orderBy('name')->get(['id', 'slug', 'name', 'country_name', 'currency_code', 'latitude', 'longitude']),
            'selectedId' => $selected?->id,
            'interests' => collect(Interests::ALL)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'paces' => collect(Interests::PACES)->map(fn ($p, $key) => ['key' => $key, 'label' => $p['label'], 'stops' => $p['stops_per_day']])->values(),
            'currencies' => Money::CURRENCIES,
            'today' => CarbonImmutable::today()->toDateString(),
            'maxDays' => TripRequest::MAX_DAYS,
        ]);
    }

    public function store(TripRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $destination = Destination::findOrFail($data['destination_id']);

        $trip = DB::transaction(function () use ($request, $data, $destination) {
            $trip = new Trip($this->tripAttributes($data));
            $trip->destination_id = $destination->id;
            $trip->user_id = $request->user()->id; // never taken from input
            $trip->title = $data['title'] ?? 'Trip to '.$destination->name;
            $trip->save();
            $trip->preferences()->create($this->prefAttributes($data));
            $this->itinerary->generate($trip);

            return $trip;
        });

        return redirect()->route('trips.show', $trip)->with('success', 'Trip created. The suggested plan below is a starting point you can edit.');
    }

    public function show(Request $request, Trip $trip): Response
    {
        $this->authorize('view', $trip);

        return Inertia::render('Trips/Show', $this->presenter->owner($trip) + [
            'categories' => ExpenseCategory::orderBy('sort_order')->get(['id', 'key', 'name']),
            'expenses' => $trip->expenses()->with('category:id,key,name')->orderBy('id')->get()->map(fn ($e) => [
                'id' => $e->id, 'category_id' => $e->expense_category_id, 'category' => $e->category->name,
                'description' => $e->description, 'currency' => $e->currency,
                'estimated_minor' => $e->estimated_minor, 'actual_minor' => $e->actual_minor,
                'estimated_input' => Money::toDecimalString($e->estimated_minor, $e->currency),
                'actual_input' => $e->actual_minor === null ? '' : Money::toDecimalString($e->actual_minor, $e->currency),
                'exponent' => Money::exponent($e->currency),
                'manual_rate' => $e->manual_rate === null ? null : rtrim(rtrim((string) $e->manual_rate, '0'), '.'),
                'rate_note' => $e->rate_note,
            ]),
            'shares' => $trip->shares()->whereNull('revoked_at')->latest()->get()->map(fn ($s) => [
                'id' => $s->id,
                'url' => route('shared.show', $s->token_encrypted),
                'include_budget_totals' => $s->include_budget_totals,
                'created_at' => $s->created_at->toIso8601String(),
                'last_viewed_at' => $s->last_viewed_at?->toIso8601String(),
            ]),
            'currencies' => Money::CURRENCIES,
            'interestOptions' => collect(Interests::ALL)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'paces' => collect(Interests::PACES)->map(fn ($p, $key) => ['key' => $key, 'label' => $p['label'], 'stops' => $p['stops_per_day']])->values(),
            'places' => $trip->destination->places()->orderByDesc('popularity')->get()->map->toMapArray()->values(),
            'weatherEnabled' => app(WeatherService::class)->enabled(),
            'routingEnabled' => app(RouteService::class)->enabled(),
            'aiAvailable' => app(ItineraryNarrator::class)->available(),
            'map' => ['tile_url' => config('roamwise.map.tile_url'), 'attribution' => config('roamwise.map.attribution')],
            'maxDays' => TripRequest::MAX_DAYS,
        ]);
    }

    public function update(TripRequest $request, Trip $trip): RedirectResponse
    {
        $this->authorize('update', $trip);
        $data = $request->validated();
        $moved = 0;

        if ($data['currency'] !== $trip->currency && $trip->expenses()->exists()) {
            throw ValidationException::withMessages([
                'currency' => 'The currency cannot change while the trip has expenses, because your exchange rates depend on it.',
            ]);
        }

        DB::transaction(function () use ($trip, $data, &$moved) {
            $trip->fill($this->tripAttributes($data));
            if (! empty($data['title'])) {
                $trip->title = $data['title'];
            }
            $trip->save();
            $trip->preferences()->updateOrCreate([], $this->prefAttributes($data));
            $moved = $this->itinerary->syncDays($trip);
        });

        $msg = 'Trip details saved. Your edited stops were kept.';
        if ($moved > 0) {
            $msg .= " {$moved} stop(s) from removed days were moved to the last day so nothing was lost.";
        }

        return back()->with('success', $msg);
    }

    public function destroy(Trip $trip): RedirectResponse
    {
        $this->authorize('delete', $trip);
        $trip->delete();

        return redirect()->route('trips.index')->with('success', 'Trip deleted.');
    }

    /** Re-run the planner. Without replace=1 only empty slots are filled; edited stops are always kept. */
    public function regenerate(Request $request, Trip $trip): RedirectResponse
    {
        $this->authorize('update', $trip);
        $replace = $request->boolean('replace');
        $result = $this->itinerary->generate($trip, $replace);

        return back()->with('success', $replace
            ? "Plan refreshed: {$result['created']} new suggestion(s). Stops you edited or added were kept."
            : "Added {$result['created']} suggestion(s) to open slots. Nothing was removed.");
    }

    public function summary(Trip $trip, ItineraryNarrator $narrator): RedirectResponse
    {
        $this->authorize('update', $trip);
        if (! $narrator->available()) {
            return back()->with('error', 'AI summaries are not configured on this server.');
        }
        $data = $this->presenter->owner($trip);
        $plan = [
            'destination' => $data['trip']['destination']['name'],
            'days' => array_map(fn ($d) => [
                'date' => $d['date'],
                'stops' => array_map(fn ($s) => ['name' => $s['name'], 'start' => $s['start_time'], 'minutes' => $s['duration_minutes']], $d['stops']),
            ], $data['days']),
        ];
        $text = $narrator->summarize($plan);
        if ($text === null) {
            return back()->with('error', 'The AI service did not return a summary. Your itinerary is unchanged.');
        }
        $trip->summary = $text;
        $trip->save();

        return back()->with('success', 'Overview written by an AI assistant from your saved stops.');
    }

    public function weather(Trip $trip, WeatherService $weather): JsonResponse
    {
        $this->authorize('view', $trip);
        $d = $trip->destination;
        try {
            return response()->json($weather->forRange(
                $d->latitude, $d->longitude,
                CarbonImmutable::parse($trip->start_date), CarbonImmutable::parse($trip->end_date), $d->timezone
            ));
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'unavailable', 'messages' => [$e->getMessage()], 'forecast' => [], 'historical' => [], 'current' => null], 422);
        }
    }

    public function route(Request $request, Trip $trip, int $day, RouteService $routes): JsonResponse
    {
        $this->authorize('view', $trip);
        $dayModel = $trip->days()->whereKey($day)->firstOrFail();
        $profile = $request->query('profile') === 'driving' ? 'driving' : 'foot';
        $points = $dayModel->stops()->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('position')->get()
            ->map(fn ($s) => [$s->latitude, $s->longitude])->all();

        return response()->json($routes->route($points, $profile) + [
            'directions_url' => Geo::directionsUrl($points, $profile === 'driving' ? 'car' : 'foot'),
        ]);
    }

    private function tripAttributes(array $d): array
    {
        return [
            'start_date' => $d['start_date'],
            'end_date' => $d['end_date'],
            'travelers' => $d['travelers'],
            'currency' => $d['currency'],
            'budget_minor' => isset($d['budget']) ? Money::toMinor($d['budget'], $d['currency']) : null,
            'pace' => $d['pace'],
            'notes' => $d['notes'] ?? null,
        ];
    }

    private function prefAttributes(array $d): array
    {
        return [
            'interests' => array_values(array_unique($d['interests'] ?? [])),
            'accessibility' => $d['accessibility'] ?? null,
            'dietary' => $d['dietary'] ?? null,
            'accommodation' => $d['accommodation'] ?? null,
            'accommodation_latitude' => $d['accommodation_latitude'] ?? null,
            'accommodation_longitude' => $d['accommodation_longitude'] ?? null,
        ];
    }
}

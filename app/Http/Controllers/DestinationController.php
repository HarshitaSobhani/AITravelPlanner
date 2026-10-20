<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\PointOfInterest;
use App\Services\Destinations\GeocodingService;
use App\Services\Trips\TripPresenter;
use App\Support\Interests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DestinationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'interest' => ['nullable', 'string', 'in:'.implode(',', Interests::keys())],
        ]);

        $query = Destination::query()->withCount('places')->search($filters['q'] ?? null);
        if (! empty($filters['interest'])) {
            // Destinations that have at least one place serving this interest.
            $interest = $filters['interest'];
            $query->whereHas('places', fn ($p) => $p->whereJsonContains('interests', $interest));
        }

        $saved = $request->user()?->savedDestinations()->pluck('destinations.id')->all() ?? [];

        return Inertia::render('Destinations/Index', [
            'destinations' => $query->orderBy('name')->paginate(9)->withQueryString()->through(fn ($d) => [
                'id' => $d->id, 'slug' => $d->slug, 'name' => $d->name, 'country_name' => $d->country_name,
                'summary' => $d->summary, 'places_count' => $d->places_count, 'saved' => in_array($d->id, $saved, true),
            ]),
            'filters' => ['q' => $filters['q'] ?? '', 'interest' => $filters['interest'] ?? ''],
            'interests' => collect(Interests::ALL)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'lookupEnabled' => app(GeocodingService::class)->enabled(),
        ]);
    }

    public function show(Request $request, Destination $destination, TripPresenter $presenter): Response
    {
        $destination->load('dataSource');
        $places = $destination->places()->orderByDesc('popularity')->get();
        $user = $request->user();

        $related = Destination::where('id', '!=', $destination->id)
            ->when($destination->country_code, fn ($q) => $q->orderByRaw('country_code = ? desc', [$destination->country_code]))
            ->orderBy('name')->limit(3)->get(['id', 'slug', 'name', 'country_name', 'summary']);

        return Inertia::render('Destinations/Show', [
            'destination' => $presenter->destination($destination) + [
                'source' => $destination->dataSource?->only(['name', 'url', 'license', 'attribution']),
                'saved' => $user ? $user->savedDestinations()->whereKey($destination->id)->exists() : false,
            ],
            'places' => $places->map->toMapArray()->values(),
            'savedPlaceIds' => $user ? $user->savedPlaces()->where('destination_id', $destination->id)->pluck('points_of_interest.id') : [],
            'categories' => $places->pluck('category')->unique()->sort()->values(),
            'interests' => collect(Interests::ALL)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'related' => $related,
            'map' => ['tile_url' => config('roamwise.map.tile_url'), 'attribution' => config('roamwise.map.attribution')],
        ]);
    }

    /** Online lookup for places outside the curated catalog (auth + rate limited). */
    public function lookup(Request $request, GeocodingService $geo): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $res = $geo->search($request->string('q')->toString());
        // Candidates are signed so that import cannot be fed arbitrary coordinates by the client.
        $res['results'] = array_map(fn ($r) => $r + ['signature' => $this->sign($r)], $res['results']);
        $res['attribution'] = GeocodingService::ATTRIBUTION;

        return response()->json($res);
    }

    public function import(Request $request, GeocodingService $geo): RedirectResponse
    {
        $d = $request->validate([
            'external_ref' => ['required', 'string', 'regex:/^open-meteo-geocoding:\d+$/'],
            'name' => ['required', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'country_name' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'signature' => ['required', 'string'],
        ]);
        $candidate = collect($d)->except('signature')->all();
        abort_unless(hash_equals($this->sign($candidate), $d['signature']), 422, 'This lookup result is no longer valid. Search again.');

        $destination = $geo->import($candidate);

        return redirect()->route('trips.create', ['destination' => $destination->slug])
            ->with('success', "{$destination->name} added. It has no stored places, so add your own stops after creating the trip.");
    }

    public function toggleSave(Request $request, Destination $destination): RedirectResponse
    {
        $request->user()->savedDestinations()->toggle($destination->id);

        return back();
    }

    public function togglePlace(Request $request, PointOfInterest $place): RedirectResponse
    {
        $request->user()->savedPlaces()->toggle($place->id);

        return back();
    }

    public function saved(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Saved', [
            'destinations' => $user->savedDestinations()->orderBy('name')->get(['destinations.id', 'slug', 'name', 'country_name']),
            'places' => $user->savedPlaces()->with('destination:id,name,slug')->orderBy('points_of_interest.name')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'category' => $p->category, 'destination' => $p->destination->only(['name', 'slug'])]),
        ]);
    }

    /** HMAC over a normalised view of the candidate, so form round-trips (strings) still verify. */
    private function sign(array $c): string
    {
        $norm = [
            'ref' => (string) ($c['external_ref'] ?? ''),
            'name' => (string) ($c['name'] ?? ''),
            'region' => (string) ($c['region'] ?? ''),
            'country' => (string) ($c['country_name'] ?? ''),
            'cc' => (string) ($c['country_code'] ?? ''),
            'lat' => sprintf('%.6f', (float) ($c['latitude'] ?? 0)),
            'lon' => sprintf('%.6f', (float) ($c['longitude'] ?? 0)),
            'tz' => (string) ($c['timezone'] ?? ''),
        ];

        return hash_hmac('sha256', json_encode($norm), (string) config('app.key'));
    }
}

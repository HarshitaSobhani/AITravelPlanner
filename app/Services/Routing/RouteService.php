<?php

namespace App\Services\Routing;

use App\Services\ProviderException;
use App\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional OSRM adapter (HTTP API: /route/v1/{profile}/{lon,lat;lon,lat}). Returns real route geometry
 * only when ROUTING_OSRM_URL is configured and the provider answers with a valid route.
 */
class RouteService
{
    public const PROFILES = ['foot', 'driving'];

    public function enabled(): bool
    {
        return filled(config('roamwise.routing.osrm_url'));
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points  [lat, lon]
     * @return array{status: string, message?: string, geometry?: list<array{0: float, 1: float}>, distance_m?: int, duration_s?: int, attribution?: string}
     */
    public function route(array $points, string $profile = 'foot'): array
    {
        if (! $this->enabled()) {
            return ['status' => 'not_configured', 'message' => 'No routing provider is configured. Use the directions link to open the stops in OpenStreetMap.'];
        }
        $points = array_values(array_filter($points, fn ($p) => Geo::validCoordinates($p[0] ?? null, $p[1] ?? null)));
        if (count($points) < 2 || count($points) > 25) {
            return ['status' => 'not_enough_stops', 'message' => 'A route needs between 2 and 25 stops with coordinates.'];
        }
        $profile = in_array($profile, self::PROFILES, true) ? $profile : 'foot';

        try {
            $coords = implode(';', array_map(fn ($p) => round($p[1], 6).','.round($p[0], 6), $points));
            $base = rtrim((string) config('roamwise.routing.osrm_url'), '/');
            $url = "{$base}/route/v1/{$profile}/{$coords}";
            $json = Cache::remember('route:'.md5($url), now()->addMinutes(config('roamwise.routing.cache_minutes')), function () use ($url) {
                $res = Http::timeout(config('roamwise.routing.timeout'))->acceptJson()->get($url, ['overview' => 'full', 'geometries' => 'geojson']);
                if (! $res->successful()) {
                    throw new ProviderException('Routing returned HTTP '.$res->status());
                }

                return $res->json();
            });

            return $this->parse($json);
        } catch (Throwable $e) {
            Log::warning('Routing failed', ['reason' => $e::class, 'message' => $e->getMessage()]);

            return ['status' => 'error', 'message' => 'The routing service did not return a route. The stop overview is still accurate.'];
        }
    }

    public function parse(mixed $json): array
    {
        $route = is_array($json) ? ($json['routes'][0] ?? null) : null;
        if (($json['code'] ?? null) !== 'Ok' || ! is_array($route)
            || ($route['geometry']['type'] ?? null) !== 'LineString'
            || ! is_array($route['geometry']['coordinates'] ?? null)
            || ! is_numeric($route['distance'] ?? null) || ! is_numeric($route['duration'] ?? null)) {
            return ['status' => 'error', 'message' => 'The routing service returned an unusable response.'];
        }
        $line = [];
        foreach ($route['geometry']['coordinates'] as $c) {
            if (! is_array($c) || ! Geo::validCoordinates($c[1] ?? null, $c[0] ?? null)) {
                return ['status' => 'error', 'message' => 'The routing service returned invalid geometry.'];
            }
            $line[] = [(float) $c[1], (float) $c[0]];
        }

        return [
            'status' => 'ok',
            'geometry' => $line,
            'distance_m' => (int) round($route['distance']),
            'duration_s' => (int) round($route['duration']),
            'attribution' => 'Routing via OSRM on OpenStreetMap data (ODbL).',
        ];
    }
}

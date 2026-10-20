<?php

namespace App\Services\Destinations;

use App\Models\DataSource;
use App\Models\Destination;
use App\Services\ProviderException;
use App\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Optional lookup of places that are not in the curated catalog, via the Open-Meteo Geocoding API
 * (https://open-meteo.com/en/docs/geocoding-api). Results are untrusted: every field is validated
 * and length-limited before use. Imported destinations carry no points of interest.
 */
class GeocodingService
{
    public const ATTRIBUTION = 'Geocoding by Open-Meteo.com, using GeoNames data (CC BY 4.0)';

    public function enabled(): bool
    {
        return config('roamwise.geocoding.provider') === 'open-meteo';
    }

    /** @return array{results: list<array>, error: ?string} */
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 80) {
            return ['results' => [], 'error' => 'Enter between 2 and 80 characters.'];
        }
        if (! $this->enabled()) {
            return ['results' => [], 'error' => 'Online destination lookup is disabled on this server.'];
        }

        try {
            $json = Cache::remember('geocode:'.md5(Str::lower($query)), now()->addMinutes(config('roamwise.geocoding.cache_minutes')), function () use ($query) {
                $res = Http::timeout(config('roamwise.geocoding.timeout'))->acceptJson()
                    ->get(config('roamwise.geocoding.url'), ['name' => $query, 'count' => 8, 'language' => 'en', 'format' => 'json']);
                if (! $res->successful()) {
                    throw new ProviderException('Geocoding returned HTTP '.$res->status());
                }
                $json = $res->json();
                if (! is_array($json)) {
                    throw new ProviderException('Geocoding returned a non-JSON body.');
                }

                return $json;
            });

            return ['results' => $this->parse($json), 'error' => null];
        } catch (Throwable $e) {
            Log::warning('Geocoding failed', ['reason' => $e::class, 'message' => $e->getMessage()]);

            return ['results' => [], 'error' => 'Online lookup is unavailable right now. The curated destinations still work.'];
        }
    }

    public function parse(array $json): array
    {
        $out = [];
        foreach ($json['results'] ?? [] as $r) {
            if (! is_array($r) || ! is_int($r['id'] ?? null) || ! is_string($r['name'] ?? null)) {
                continue;
            }
            if (! Geo::validCoordinates($r['latitude'] ?? null, $r['longitude'] ?? null)) {
                continue;
            }
            $cc = isset($r['country_code']) && is_string($r['country_code']) && preg_match('/^[A-Za-z]{2}$/', $r['country_code'])
                ? strtoupper($r['country_code']) : null;
            $out[] = [
                'external_ref' => 'open-meteo-geocoding:'.$r['id'],
                'name' => Str::limit(strip_tags($r['name']), 120, ''),
                'region' => isset($r['admin1']) && is_string($r['admin1']) ? Str::limit(strip_tags($r['admin1']), 120, '') : null,
                'country_name' => isset($r['country']) && is_string($r['country']) ? Str::limit(strip_tags($r['country']), 120, '') : null,
                'country_code' => $cc,
                'latitude' => round((float) $r['latitude'], 6),
                'longitude' => round((float) $r['longitude'], 6),
                'timezone' => isset($r['timezone']) && is_string($r['timezone']) && preg_match('#^[A-Za-z_]+(/[A-Za-z_+\-0-9]+)*$#', $r['timezone']) ? $r['timezone'] : null,
            ];
        }

        return $out;
    }

    /** Store a validated lookup result as a destination (idempotent on external_ref). */
    public function import(array $candidate): Destination
    {
        $source = DataSource::firstOrCreate(['key' => 'open-meteo-geocoding'], [
            'name' => 'Open-Meteo Geocoding API', 'url' => 'https://open-meteo.com/en/docs/geocoding-api',
            'license' => 'CC BY 4.0 (GeoNames-derived data)', 'attribution' => self::ATTRIBUTION,
        ]);

        $existing = Destination::where('external_ref', $candidate['external_ref'])->first();
        if ($existing) {
            return $existing;
        }

        $slug = Str::slug($candidate['name'].'-'.($candidate['country_code'] ?? '')) ?: 'place';
        $base = $slug;
        $n = 2;
        while (Destination::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return Destination::create([
            'data_source_id' => $source->id,
            'slug' => $slug,
            'name' => $candidate['name'],
            'type' => 'city',
            'country_code' => $candidate['country_code'],
            'country_name' => $candidate['country_name'],
            'latitude' => $candidate['latitude'],
            'longitude' => $candidate['longitude'],
            'timezone' => $candidate['timezone'],
            'summary' => trim(($candidate['region'] ? $candidate['region'].', ' : '').($candidate['country_name'] ?? '')) ?: null,
            'external_ref' => $candidate['external_ref'],
        ]);
    }
}

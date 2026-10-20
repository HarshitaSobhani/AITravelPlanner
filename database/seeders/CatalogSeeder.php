<?php

namespace Database\Seeders;

use App\Models\DataSource;
use App\Models\Destination;
use App\Models\PointOfInterest;
use App\Support\Geo;
use App\Support\Interests;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the curated catalog from database/data/catalog.json. Idempotent: re-running updates in place
 * and never creates duplicates. Entries with invalid coordinates or unknown interests abort the seed.
 */
class CatalogSeeder extends Seeder
{
    public function run(?string $path = null): void
    {
        $path ??= database_path('data/catalog.json');
        $catalog = json_decode((string) file_get_contents($path), true);
        if (! is_array($catalog)) {
            throw new RuntimeException("Catalog at {$path} is not valid JSON.");
        }

        $curated = DataSource::updateOrCreate(['key' => 'roamwise-curated'], [
            'name' => 'RoamWise curated catalog',
            'url' => null,
            'license' => 'Facts and coordinates only; descriptions written for this project',
            'attribution' => 'Coordinates cross-checked against OpenStreetMap contributors (ODbL) via Nominatim where noted.',
            'notes' => 'Hand-curated landmarks. No opening hours, prices or images are stored.',
        ]);
        DataSource::updateOrCreate(['key' => 'open-meteo-geocoding'], [
            'name' => 'Open-Meteo Geocoding API',
            'url' => 'https://open-meteo.com/en/docs/geocoding-api',
            'license' => 'CC BY 4.0 (GeoNames-derived data)',
            'attribution' => 'Geocoding data by Open-Meteo.com / GeoNames.',
        ]);

        DB::transaction(function () use ($catalog, $curated) {
            foreach ($catalog as $d) {
                $this->assertCoordinates($d['name'], $d['latitude'], $d['longitude']);
                $destination = Destination::updateOrCreate(['slug' => $d['slug']], [
                    'data_source_id' => $curated->id,
                    'name' => $d['name'],
                    'type' => 'city',
                    'country_name' => $d['country_name'],
                    'country_code' => $d['country_code'],
                    'currency_code' => $d['currency_code'],
                    'timezone' => $d['timezone'],
                    'latitude' => $d['latitude'],
                    'longitude' => $d['longitude'],
                    'best_season' => $d['best_season'] ?? null,
                    'summary' => $d['summary'] ?? null,
                ]);

                foreach ($d['places'] as $p) {
                    $this->assertCoordinates($p['name'], $p['latitude'], $p['longitude']);
                    $bad = array_diff($p['interests'], Interests::keys());
                    if ($bad) {
                        throw new RuntimeException("Unknown interests for {$p['name']}: ".implode(',', $bad));
                    }
                    PointOfInterest::updateOrCreate(
                        ['destination_id' => $destination->id, 'slug' => $p['slug']],
                        [
                            'data_source_id' => $curated->id,
                            'name' => $p['name'],
                            'category' => $p['category'],
                            'interests' => $p['interests'],
                            'description' => $p['description'] ?? null,
                            'latitude' => $p['latitude'],
                            'longitude' => $p['longitude'],
                            'neighbourhood' => $p['neighbourhood'] ?? null,
                            'suggested_duration_minutes' => $p['suggested_duration_minutes'],
                            'popularity' => $p['popularity'],
                            'osm_ref' => $p['osm_ref'] ?? null,
                            'coordinates_verified_at' => isset($p['verified_on']) ? $p['verified_on'] : null,
                        ]
                    );
                }
            }
        });
    }

    private function assertCoordinates(string $name, mixed $lat, mixed $lon): void
    {
        if (! Geo::validCoordinates($lat, $lon)) {
            throw new RuntimeException("Invalid coordinates for {$name}.");
        }
    }
}

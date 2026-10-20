<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\PointOfInterest;
use App\Models\User;
use App\Support\Geo;
use App\Support\Interests;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_catalog_is_valid_and_idempotent(): void
    {
        $this->seed(CatalogSeeder::class);
        $d = Destination::count();
        $p = PointOfInterest::count();
        $this->assertGreaterThanOrEqual(6, $d);
        $this->assertGreaterThanOrEqual(60, $p);

        $this->seed(CatalogSeeder::class);
        $this->assertSame($d, Destination::count());
        $this->assertSame($p, PointOfInterest::count());
    }

    public function test_every_stored_coordinate_is_valid_and_near_its_destination(): void
    {
        $this->seed(CatalogSeeder::class);
        foreach (PointOfInterest::with('destination')->get() as $poi) {
            $this->assertTrue(Geo::validCoordinates($poi->latitude, $poi->longitude), "{$poi->name} has invalid coordinates");
            $km = Geo::haversineKm($poi->latitude, $poi->longitude, $poi->destination->latitude, $poi->destination->longitude);
            $this->assertLessThan(30, $km, "{$poi->name} is {$km} km from {$poi->destination->name}");
            $this->assertEmpty(array_diff($poi->interests, Interests::keys()), "{$poi->name} has unknown interests");
            $this->assertNull($poi->opening_hours, 'catalog must not contain unverified opening hours');
        }
    }

    public function test_no_duplicate_places_within_a_destination(): void
    {
        $this->seed(CatalogSeeder::class);
        foreach (Destination::with('places')->get() as $d) {
            $names = $d->places->pluck('name')->map(fn ($n) => mb_strtolower($n));
            $this->assertSame($names->count(), $names->unique()->count(), "duplicate place names in {$d->name}");
        }
    }

    public function test_seeder_rejects_invalid_coordinates(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cat');
        file_put_contents($path, json_encode([[
            'slug' => 'x', 'name' => 'X', 'country_name' => 'Y', 'country_code' => 'YY', 'currency_code' => 'EUR', 'timezone' => 'UTC',
            'latitude' => 10, 'longitude' => 10, 'places' => [['slug' => 'bad', 'name' => 'Bad', 'category' => 'landmark', 'interests' => ['food'],
                'latitude' => 200, 'longitude' => 10, 'suggested_duration_minutes' => 60, 'popularity' => 50]],
        ]]));
        $this->expectException(\RuntimeException::class);
        try {
            (new CatalogSeeder)->run($path);
        } finally {
            unlink($path);
            $this->assertSame(0, Destination::count(), 'failed seed rolls back');
        }
    }

    public function test_search_pagination_and_interest_filter(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->get('/destinations?q=kyo')->assertInertia(fn ($p) => $p->component('Destinations/Index')->has('destinations.data', 1)->where('destinations.data.0.slug', 'kyoto'));
        $this->get('/destinations?q=zzzz')->assertInertia(fn ($p) => $p->has('destinations.data', 0));
        $this->get('/destinations?interest=literature')->assertInertia(fn ($p) => $p->has('destinations.data'));
        $this->get('/destinations?page=2')->assertInertia(fn ($p) => $p->has('destinations.data', 0)->where('destinations.current_page', 2));
        $this->get('/destinations?interest=bogus')->assertSessionHasErrors('interest');
    }

    public function test_like_wildcards_in_search_are_escaped(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->get('/destinations?q=%25')->assertInertia(fn ($p) => $p->has('destinations.data', 0));
    }

    public function test_detail_page_exposes_only_stored_places(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->get('/destinations/paris')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Destinations/Show')->where('destination.name', 'Paris')
            ->has('places', fn ($places) => $places->etc())
            ->has('related', 3)->has('map.attribution'));
        $this->get('/destinations/nowhere')->assertNotFound();
    }

    public function test_save_and_unsave_destination_and_place(): void
    {
        $this->seed(CatalogSeeder::class);
        $user = User::factory()->create();
        $d = Destination::where('slug', 'rome')->first();
        $poi = $d->places()->first();

        $this->actingAs($user)->post('/destinations/rome/save');
        $this->actingAs($user)->post("/places/{$poi->id}/save");
        $this->assertTrue($user->savedDestinations()->whereKey($d->id)->exists());
        $this->assertTrue($user->savedPlaces()->whereKey($poi->id)->exists());
        $this->actingAs($user)->get('/saved')->assertInertia(fn ($p) => $p->component('Saved')->has('destinations', 1)->has('places', 1));

        $this->actingAs($user)->post('/destinations/rome/save');
        $this->assertFalse($user->savedDestinations()->whereKey($d->id)->exists());
    }

    public function test_saved_items_are_private_to_each_user(): void
    {
        $this->seed(CatalogSeeder::class);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a)->post('/destinations/rome/save');
        $this->actingAs($b)->get('/saved')->assertInertia(fn ($p) => $p->has('destinations', 0));
    }

    public function test_empty_database_renders_pages(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($p) => $p->component('Welcome')->has('destinations', 0)->has('examples', 0));
        $this->get('/destinations')->assertOk();
    }
}

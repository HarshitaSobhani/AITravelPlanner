<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\HttpNarrator;
use App\Services\Destinations\GeocodingService;
use App\Services\ProviderException;
use App\Services\Routing\RouteService;
use App\Services\Weather\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

class ExternalProvidersTest extends TestCase
{
    use BuildsTrips, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function daily(array $dates): array
    {
        $n = count($dates);

        return ['daily' => [
            'time' => $dates,
            'weather_code' => array_fill(0, $n, 61),
            'temperature_2m_max' => array_fill(0, $n, 21.46),
            'temperature_2m_min' => array_fill(0, $n, 12.0),
            'precipitation_sum' => array_fill(0, $n, 3.2),
            'precipitation_probability_max' => array_fill(0, $n, 60),
        ], 'current' => ['time' => '2030-01-01T10:00', 'temperature_2m' => 15.2, 'weather_code' => 2, 'wind_speed_10m' => 9.1]];
    }

    private function service(string $now = '2030-05-01'): WeatherService
    {
        return new WeatherService(CarbonImmutable::parse($now));
    }

    public function test_forecast_within_horizon(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response($this->daily(['2030-05-03', '2030-05-04']))]);
        $r = $this->service()->forRange(48.85, 2.35, CarbonImmutable::parse('2030-05-03'), CarbonImmutable::parse('2030-05-04'), 'Europe/Paris');
        $this->assertSame('ok', $r['status']);
        $this->assertCount(2, $r['forecast']);
        $this->assertSame('Slight rain', $r['forecast'][0]['summary']);
        $this->assertSame(21.5, $r['forecast'][0]['temp_max_c']);
        $this->assertSame([], $r['historical']);
        $this->assertSame(15.2, $r['current']['temp_c']);
        $this->assertStringContainsString('Open-Meteo', $r['attribution']);
    }

    public function test_dates_beyond_horizon_have_no_forecast_and_say_so(): void
    {
        Http::fake(['archive-api.open-meteo.com/*' => Http::response($this->daily(['2029-09-10', '2029-09-11']))]);
        $r = $this->service()->forRange(48.85, 2.35, CarbonImmutable::parse('2030-09-10'), CarbonImmutable::parse('2030-09-11'));
        $this->assertSame([], $r['forecast']);
        $this->assertCount(2, $r['historical']);
        $this->assertSame('partial', $r['status']);
        $this->assertStringContainsString('Forecasts are only available up to', $r['messages'][0]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'archive-api') && str_contains($req->url(), 'start_date=2029-09-10'));
    }

    public function test_provider_failure_degrades_gracefully(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $r = $this->service()->forRange(48.85, 2.35, CarbonImmutable::parse('2030-05-03'), CarbonImmutable::parse('2030-05-04'));
        $this->assertSame('unavailable', $r['status']);
        $this->assertSame([], $r['forecast']);
        $this->assertNotEmpty($r['messages']);
    }

    public function test_timeouts_degrade_gracefully(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);
        $r = $this->service()->forRange(48.85, 2.35, CarbonImmutable::parse('2030-05-03'), CarbonImmutable::parse('2030-05-04'));
        $this->assertSame('unavailable', $r['status']);
    }

    public function test_malformed_provider_payloads_are_rejected_not_displayed(): void
    {
        foreach ([
            ['daily' => ['time' => ['2030-05-03']]],
            ['daily' => ['time' => ['not-a-date'], 'weather_code' => [1], 'temperature_2m_max' => [1], 'temperature_2m_min' => [1], 'precipitation_sum' => [1]]],
            ['daily' => ['time' => ['2030-05-03', '2030-05-04'], 'weather_code' => [1], 'temperature_2m_max' => [1], 'temperature_2m_min' => [1], 'precipitation_sum' => [1]]],
            ['unexpected' => true],
        ] as $bad) {
            Cache::flush();
            Http::fake(['*' => Http::response($bad)]);
            $r = $this->service()->forRange(48.85, 2.35, CarbonImmutable::parse('2030-05-03'), CarbonImmutable::parse('2030-05-04'));
            $this->assertSame('unavailable', $r['status']);
            $this->assertSame([], $r['forecast']);
        }
    }

    public function test_null_values_stay_null(): void
    {
        $payload = $this->daily(['2030-05-03']);
        $payload['daily']['temperature_2m_max'] = [null];
        $payload['daily']['weather_code'] = [null];
        Http::fake(['*' => Http::response($payload)]);
        $r = $this->service()->forRange(48.85, 2.35, CarbonImmutable::parse('2030-05-03'), CarbonImmutable::parse('2030-05-03'));
        $this->assertNull($r['forecast'][0]['temp_max_c']);
        $this->assertNull($r['forecast'][0]['summary']);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->forRange(120, 2.35, CarbonImmutable::parse('2030-05-03'), CarbonImmutable::parse('2030-05-04'));
    }

    public function test_reversed_and_overlong_ranges_are_rejected(): void
    {
        foreach ([['2030-05-05', '2030-05-01'], ['2030-05-01', '2030-07-30']] as [$s, $e]) {
            try {
                $this->service()->forRange(1.0, 1.0, CarbonImmutable::parse($s), CarbonImmutable::parse($e));
                $this->fail('expected exception');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_responses_are_cached(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response($this->daily(['2030-05-03']))]);
        $s = $this->service();
        $s->forRange(48.85, 2.35, CarbonImmutable::parse('2030-05-03'), CarbonImmutable::parse('2030-05-03'));
        $s->forRange(48.85, 2.35, CarbonImmutable::parse('2030-05-03'), CarbonImmutable::parse('2030-05-03'));
        Http::assertSentCount(1);
    }

    public function test_disabled_weather_provider(): void
    {
        config(['roamwise.weather.provider' => 'none']);
        $r = $this->service()->forRange(48.85, 2.35, CarbonImmutable::parse('2030-05-03'), CarbonImmutable::parse('2030-05-03'));
        $this->assertSame('unavailable', $r['status']);
        Http::assertNothingSent();
    }

    public function test_weather_endpoint_uses_the_trips_destination_coordinates(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $user = User::factory()->create();
        $trip = $this->createTripVia($user, $this->destinationWithPlaces(3));
        $this->actingAs($user)->getJson("/trips/{$trip->id}/weather")->assertOk()->assertJsonPath('status', 'unavailable');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'latitude=48.8566'));
    }

    // --- geocoding ---

    public function test_geocoding_parses_and_validates_results(): void
    {
        $svc = new GeocodingService;
        $out = $svc->parse(['results' => [
            ['id' => 1, 'name' => 'Kyoto', 'latitude' => 35.02, 'longitude' => 135.75, 'country' => 'Japan', 'country_code' => 'JP', 'admin1' => 'Kyoto', 'timezone' => 'Asia/Tokyo'],
            ['id' => 2, 'name' => 'Bad', 'latitude' => 999, 'longitude' => 1],
            ['id' => 3, 'name' => '<b>Tag</b>', 'latitude' => 10, 'longitude' => 10, 'country_code' => 'ZZZ', 'timezone' => '../etc'],
            'junk', ['id' => 'x', 'name' => 'NoInt', 'latitude' => 1, 'longitude' => 1],
        ]]);
        $this->assertCount(2, $out);
        $this->assertSame('open-meteo-geocoding:1', $out[0]['external_ref']);
        $this->assertSame('Tag', $out[1]['name']);
        $this->assertNull($out[1]['country_code']);
        $this->assertNull($out[1]['timezone']);
    }

    public function test_lookup_endpoint_failure_returns_message_and_empty_results(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/lookup/destinations?q=Kyoto')->assertOk()->assertJsonPath('results', [])->assertJsonStructure(['error']);
    }

    public function test_import_requires_a_server_signed_candidate_and_is_idempotent(): void
    {
        Http::fake(['geocoding-api.open-meteo.com/*' => Http::response(['results' => [
            ['id' => 77, 'name' => 'Ghent', 'latitude' => 51.05, 'longitude' => 3.72, 'country' => 'Belgium', 'country_code' => 'BE', 'timezone' => 'Europe/Brussels'],
        ]])]);
        $user = User::factory()->create();
        $c = $this->actingAs($user)->getJson('/lookup/destinations?q=Ghent')->assertOk()->json('results.0');

        // Tampered coordinates are refused.
        $this->actingAs($user)->post('/lookup/destinations/import', array_merge($c, ['latitude' => 10.0]))->assertStatus(422);
        $this->assertDatabaseCount('destinations', 0);

        $this->actingAs($user)->post('/lookup/destinations/import', $c)->assertRedirect();
        $this->actingAs($user)->post('/lookup/destinations/import', $c)->assertRedirect();
        $this->assertDatabaseCount('destinations', 1);
        $this->assertDatabaseHas('destinations', ['external_ref' => 'open-meteo-geocoding:77', 'name' => 'Ghent']);
    }

    // --- routing ---

    public function test_routing_not_configured_returns_no_geometry(): void
    {
        config(['roamwise.routing.osrm_url' => null]);
        $r = (new RouteService)->route([[48.1, 2.1], [48.2, 2.2]]);
        $this->assertSame('not_configured', $r['status']);
        $this->assertArrayNotHasKey('geometry', $r);
        Http::assertNothingSent();
    }

    public function test_routing_returns_geometry_only_from_provider(): void
    {
        config(['roamwise.routing.osrm_url' => 'https://osrm.example.test']);
        Http::fake(['osrm.example.test/*' => Http::response(['code' => 'Ok', 'routes' => [[
            'distance' => 1234.4, 'duration' => 900.2, 'geometry' => ['type' => 'LineString', 'coordinates' => [[2.1, 48.1], [2.15, 48.15], [2.2, 48.2]]],
        ]]])]);
        $r = (new RouteService)->route([[48.1, 2.1], [48.2, 2.2]], 'foot');
        $this->assertSame('ok', $r['status']);
        $this->assertSame([[48.1, 2.1], [48.15, 2.15], [48.2, 2.2]], $r['geometry']);
        $this->assertSame(1234, $r['distance_m']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/route/v1/foot/2.1,48.1;2.2,48.2'));
    }

    public function test_routing_failures_and_bad_geometry_are_handled(): void
    {
        config(['roamwise.routing.osrm_url' => 'https://osrm.example.test']);
        Http::fake(['*' => Http::response('x', 500)]);
        $this->assertSame('error', (new RouteService)->route([[48.1, 2.1], [48.2, 2.2]])['status']);

        Cache::flush();
        Http::fake(['*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 1, 'duration' => 1, 'geometry' => ['type' => 'LineString', 'coordinates' => [[2.1, 999]]]]]])]);
        $this->assertSame('error', (new RouteService)->route([[48.1, 2.1], [48.2, 2.2]])['status']);

        $this->assertSame('not_enough_stops', (new RouteService)->route([[48.1, 2.1]])['status']);
    }

    public function test_route_endpoint_includes_external_directions_link_without_a_provider(): void
    {
        config(['roamwise.routing.osrm_url' => null]);
        $user = User::factory()->create();
        $trip = $this->createTripVia($user, $this->destinationWithPlaces(8));
        $day = $trip->days()->first();
        $this->actingAs($user)->getJson("/trips/{$trip->id}/days/{$day->id}/route")
            ->assertOk()->assertJsonPath('status', 'not_configured')->assertJsonMissingPath('geometry')
            ->assertJsonPath('directions_url', fn ($u) => str_starts_with($u, 'https://www.openstreetmap.org/directions'));
    }

    // --- AI ---

    public function test_planning_works_without_ai_and_summary_reports_unconfigured(): void
    {
        config(['roamwise.ai.provider' => 'none']);
        $user = User::factory()->create();
        $trip = $this->createTripVia($user, $this->destinationWithPlaces(8));
        $this->assertGreaterThan(0, $trip->stops()->count());
        $this->actingAs($user)->post("/trips/{$trip->id}/summary")->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_ai_summary_is_stored_and_cleaned_and_never_alters_stops(): void
    {
        config(['roamwise.ai.provider' => 'anthropic', 'roamwise.ai.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '<b>Enjoy</b> your trip.']]])]);
        $user = User::factory()->create();
        $trip = $this->createTripVia($user, $this->destinationWithPlaces(8));
        $before = $trip->stops()->pluck('id')->all();

        $this->actingAs($user)->post("/trips/{$trip->id}/summary")->assertSessionHas('success');
        $this->assertSame('Enjoy your trip.', $trip->fresh()->summary);
        $this->assertSame($before, $trip->stops()->pluck('id')->all());
        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'test-key') && ! str_contains($r->body(), 'private'));
    }

    public function test_ai_failure_leaves_itinerary_untouched(): void
    {
        config(['roamwise.ai.provider' => 'anthropic', 'roamwise.ai.anthropic.key' => 'test-key']);
        Http::fake(['*' => Http::response('err', 529)]);
        $user = User::factory()->create();
        $trip = $this->createTripVia($user, $this->destinationWithPlaces(8));
        $this->actingAs($user)->post("/trips/{$trip->id}/summary")->assertSessionHas('error');
        $this->assertNull($trip->fresh()->summary);
    }

    public function test_narrator_output_cleaning(): void
    {
        $this->assertNull(HttpNarrator::clean(null));
        $this->assertNull(HttpNarrator::clean('   '));
        $this->assertSame(1500, mb_strlen(HttpNarrator::clean(str_repeat('a', 3000))));
    }

    public function test_provider_exception_is_a_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new ProviderException('x'));
    }
}

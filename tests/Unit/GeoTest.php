<?php

namespace Tests\Unit;

use App\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    public function test_haversine_known_distance(): void
    {
        // Paris -> London is about 344 km
        $km = Geo::haversineKm(48.8566, 2.3522, 51.5074, -0.1278);
        $this->assertEqualsWithDelta(344, $km, 5);
    }

    public function test_coordinate_validation(): void
    {
        $this->assertTrue(Geo::validCoordinates(48.85, 2.35));
        $this->assertFalse(Geo::validCoordinates(91, 0));
        $this->assertFalse(Geo::validCoordinates(10, 181));
        $this->assertFalse(Geo::validCoordinates(null, 5));
        $this->assertFalse(Geo::validCoordinates('abc', 5));
        $this->assertFalse(Geo::validCoordinates(0, 0), 'null island is treated as missing data');
    }

    public function test_safe_url_only_allows_http(): void
    {
        $this->assertSame('https://example.org/a', Geo::safeUrl('https://example.org/a'));
        $this->assertNull(Geo::safeUrl('javascript:alert(1)'));
        $this->assertNull(Geo::safeUrl('data:text/html,hi'));
        $this->assertNull(Geo::safeUrl(''));
    }

    public function test_directions_url_needs_two_points(): void
    {
        $this->assertNull(Geo::directionsUrl([[1, 2]]));
        $this->assertStringStartsWith('https://www.openstreetmap.org/directions', Geo::directionsUrl([[48.1, 2.1], [48.2, 2.2]]));
    }
}

<?php

namespace App\Support;

final class Geo
{
    /** Great-circle distance in kilometres. */
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    public static function validCoordinates(mixed $lat, mixed $lon): bool
    {
        return is_numeric($lat) && is_numeric($lon)
            && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180
            && ! ((float) $lat === 0.0 && (float) $lon === 0.0);
    }

    /** Only http(s) URLs are ever rendered as links. */
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    public static function directionsUrl(array $points, string $mode = 'foot'): ?string
    {
        if (count($points) < 2) {
            return null;
        }
        $route = implode(';', array_map(fn ($p) => round($p[0], 6).','.round($p[1], 6), $points));
        $engine = $mode === 'car' ? 'fossgis_osrm_car' : 'fossgis_osrm_foot';

        return 'https://www.openstreetmap.org/directions?engine='.$engine.'&route='.rawurlencode($route);
    }
}

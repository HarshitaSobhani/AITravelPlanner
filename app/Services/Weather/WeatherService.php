<?php

namespace App\Services\Weather;

use App\Services\ProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Open-Meteo adapter. Keeps three kinds of data strictly separate:
 *  - current:    conditions right now at the destination
 *  - forecast:   model forecast, only within the provider's horizon
 *  - historical: observed weather on the same calendar dates one year earlier (NOT a forecast)
 * Nothing is ever estimated or invented; missing values stay null.
 */
class WeatherService
{
    public const MAX_RANGE_DAYS = 31;

    public const ATTRIBUTION = 'Weather data by Open-Meteo.com (CC BY 4.0)';

    public function __construct(private readonly ?CarbonImmutable $now = null) {}

    public function enabled(): bool
    {
        return config('roamwise.weather.provider') === 'open-meteo';
    }

    public function forRange(float $lat, float $lon, CarbonImmutable $start, CarbonImmutable $end, ?string $timezone = null): array
    {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            throw new InvalidArgumentException('Invalid coordinates.');
        }
        if ($end->lt($start)) {
            throw new InvalidArgumentException('End date is before start date.');
        }
        if ($start->diffInDays($end) + 1 > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('Weather lookups are limited to '.self::MAX_RANGE_DAYS.' days.');
        }

        $base = [
            'status' => 'unavailable',
            'current' => null,
            'forecast' => [],
            'historical' => [],
            'messages' => [],
            'attribution' => self::ATTRIBUTION,
            'fetched_at' => null,
        ];

        if (! $this->enabled()) {
            $base['messages'][] = 'Weather lookups are disabled on this server. Itinerary planning is unaffected.';

            return $base;
        }

        $today = ($this->now ?? CarbonImmutable::now('UTC'))->startOfDay();
        $lastForecast = $today->addDays(config('roamwise.weather.forecast_horizon_days') - 1);
        $tz = $timezone ?: 'auto';

        $fStart = $start->max($today);
        $fEnd = $end->min($lastForecast);
        $hasForecastWindow = $fStart->lte($fEnd);

        if ($hasForecastWindow) {
            try {
                $res = $this->forecast($lat, $lon, $fStart, $fEnd, $tz);
                $base['forecast'] = $res['days'];
                $base['current'] = $res['current'];
                $base['fetched_at'] = now()->toIso8601String();
            } catch (Throwable $e) {
                Log::warning('Weather forecast unavailable', ['reason' => $e::class, 'message' => $e->getMessage()]);
                $base['messages'][] = 'The forecast service did not respond. Try again later; planning can continue without it.';
            }
        }

        if ($end->gt($lastForecast)) {
            $first = $start->gt($lastForecast) ? $start : $lastForecast->addDay();
            $base['messages'][] = 'Forecasts are only available up to '.$lastForecast->toFormattedDateString()
                .". Days from {$first->toFormattedDateString()} have no forecast.";
        }

        // Historical reference: observed weather, same dates one year earlier, for any day without a forecast.
        $needHistory = ! $hasForecastWindow || $start->lt($today) || $end->gt($lastForecast);
        if ($needHistory) {
            $hStart = $start->subYear();
            $hEnd = $end->subYear();
            $archiveLimit = $today->subDays(7);
            if ($hEnd->lte($archiveLimit)) {
                try {
                    $base['historical'] = $this->archive($lat, $lon, $hStart, $hEnd, $tz);
                    $base['fetched_at'] ??= now()->toIso8601String();
                } catch (Throwable $e) {
                    Log::warning('Weather archive unavailable', ['reason' => $e::class, 'message' => $e->getMessage()]);
                    $base['messages'][] = 'Historical weather could not be loaded right now.';
                }
            }
        }

        $base['status'] = $base['forecast'] || $base['historical'] ? ($base['messages'] ? 'partial' : 'ok') : 'unavailable';
        if ($base['status'] === 'unavailable' && ! $base['messages']) {
            $base['messages'][] = 'No weather data is available for these dates.';
        }

        return $base;
    }

    private function forecast(float $lat, float $lon, CarbonImmutable $s, CarbonImmutable $e, string $tz): array
    {
        $params = [
            'latitude' => round($lat, 4), 'longitude' => round($lon, 4),
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max',
            'current' => 'temperature_2m,weather_code,wind_speed_10m',
            'timezone' => $tz,
            'start_date' => $s->toDateString(), 'end_date' => $e->toDateString(),
        ];
        $json = $this->get(config('roamwise.weather.forecast_url'), $params);

        return [
            'days' => $this->parseDaily($json, true),
            'current' => $this->parseCurrent($json),
        ];
    }

    private function archive(float $lat, float $lon, CarbonImmutable $s, CarbonImmutable $e, string $tz): array
    {
        $params = [
            'latitude' => round($lat, 4), 'longitude' => round($lon, 4),
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum',
            'timezone' => $tz,
            'start_date' => $s->toDateString(), 'end_date' => $e->toDateString(),
        ];

        return $this->parseDaily($this->get(config('roamwise.weather.archive_url'), $params), false);
    }

    private function get(string $url, array $params): array
    {
        $key = 'weather:'.md5($url.json_encode($params));
        $minutes = config('roamwise.weather.cache_minutes');

        return Cache::remember($key, now()->addMinutes($minutes), function () use ($url, $params) {
            $res = Http::timeout(config('roamwise.weather.timeout'))->acceptJson()->retry(1, 200, throw: false)->get($url, $params);
            if (! $res->successful()) {
                throw new ProviderException('Weather provider returned HTTP '.$res->status());
            }
            $json = $res->json();
            if (! is_array($json)) {
                throw new ProviderException('Weather provider returned a non-JSON body.');
            }

            return $json;
        });
    }

    public function parseDaily(array $json, bool $withProbability): array
    {
        $d = $json['daily'] ?? null;
        if (! is_array($d) || ! isset($d['time']) || ! is_array($d['time'])) {
            throw new ProviderException('Weather response is missing daily data.');
        }
        $n = count($d['time']);
        foreach (['weather_code', 'temperature_2m_max', 'temperature_2m_min', 'precipitation_sum'] as $k) {
            if (! isset($d[$k]) || ! is_array($d[$k]) || count($d[$k]) !== $n) {
                throw new ProviderException("Weather response field {$k} is malformed.");
            }
        }
        $out = [];
        foreach ($d['time'] as $i => $date) {
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new ProviderException('Weather response contains an invalid date.');
            }
            $num = fn ($v) => is_numeric($v) ? round((float) $v, 1) : null;
            $row = [
                'date' => $date,
                'summary' => $d['weather_code'][$i] === null ? null : WeatherCodes::label($d['weather_code'][$i]),
                'temp_max_c' => $num($d['temperature_2m_max'][$i]),
                'temp_min_c' => $num($d['temperature_2m_min'][$i]),
                'precipitation_mm' => $num($d['precipitation_sum'][$i]),
            ];
            if ($withProbability) {
                $p = $d['precipitation_probability_max'][$i] ?? null;
                $row['precipitation_probability'] = is_numeric($p) ? (int) $p : null;
            }
            $out[] = $row;
        }

        return $out;
    }

    public function parseCurrent(array $json): ?array
    {
        $c = $json['current'] ?? null;
        if (! is_array($c) || ! isset($c['temperature_2m']) || ! is_numeric($c['temperature_2m'])) {
            return null;
        }

        return [
            'time' => is_string($c['time'] ?? null) ? $c['time'] : null,
            'temp_c' => round((float) $c['temperature_2m'], 1),
            'summary' => WeatherCodes::label($c['weather_code'] ?? null),
            'wind_kmh' => is_numeric($c['wind_speed_10m'] ?? null) ? round((float) $c['wind_speed_10m'], 1) : null,
        ];
    }
}

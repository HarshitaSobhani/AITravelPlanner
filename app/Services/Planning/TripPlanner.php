<?php

namespace App\Services\Planning;

use App\Support\Geo;
use Carbon\CarbonImmutable;

/**
 * Deterministic baseline itinerary planner. Pure: takes arrays in, returns arrays out, touches no
 * database, network or AI. The same input always yields the same plan.
 *
 * Input keys:
 *  - dates:    list<string>  Y-m-d, one per day
 *  - pace:     relaxed|balanced|packed
 *  - interests list<string>
 *  - places:   list<array{id,name,latitude,longitude,interests,popularity,duration,opening_hours?}>
 *  - existing: array<int, list<array{place_id?,latitude?,longitude?,start_time?,duration}>>  stops already on each day index (kept as-is)
 *  - exclude:  list<int> place ids that must not be used (already in trip)
 *  - origin:   ?array{0:float,1:float} accommodation coordinates
 *
 * Output: ['days' => array<int, list<array>>, 'warnings' => list<array{code,message}>]
 */
class TripPlanner
{
    public const DAY_START_MIN = 570;  // 09:30

    public const DAY_END_MIN = 1230;   // 20:30 latest finish

    public const LUNCH_FROM_MIN = 720; // 12:00

    public const LUNCH_TO_MIN = 810;   // 13:30

    public const LUNCH_LENGTH = 60;

    public const STOPS_PER_DAY = ['relaxed' => 2, 'balanced' => 3, 'packed' => 5];

    public function plan(array $in): array
    {
        $dates = array_values($in['dates'] ?? []);
        $pace = $in['pace'] ?? 'balanced';
        $perDay = self::STOPS_PER_DAY[$pace] ?? 3;
        $interests = array_values(array_unique($in['interests'] ?? []));
        $existing = $in['existing'] ?? [];
        $origin = $in['origin'] ?? null;
        $warnings = [];
        $days = array_fill(0, count($dates), []);

        if (count($dates) === 0) {
            return ['days' => [], 'warnings' => []];
        }

        $candidates = $this->dedupe($this->usable($in['places'] ?? [], $in['exclude'] ?? []));
        if (count($candidates) === 0) {
            $warnings[] = $this->warn('no_places', 'No stored places are available for this destination, so no stops were suggested. You can add your own stops.');

            return ['days' => $days, 'warnings' => $warnings];
        }

        $capacity = [];
        foreach ($dates as $i => $_) {
            $capacity[$i] = max(0, $perDay - count($existing[$i] ?? []));
        }
        $slots = array_sum($capacity);

        [$selected, $backfilled] = $this->select($candidates, $interests, $slots);
        if ($interests && $backfilled > 0) {
            $warnings[] = $this->warn('few_matches', 'Only '.(count($selected) - $backfilled)." stored places match your interests; $backfilled popular general place(s) were added to fill the plan.");
        }
        if (count($selected) < $slots) {
            $warnings[] = $this->warn('few_places', 'The stored catalog has '.count($selected)." place(s) available for $slots open slot(s), so some days have fewer stops than your pace allows.");
        }

        $assigned = $this->assign($selected, $capacity, $existing);

        $dropped = 0;
        $usedHours = false;
        foreach ($assigned as $i => $dayPlaces) {
            $ordered = $this->order($dayPlaces, $origin);
            [$stops, $drop, $hours] = $this->schedule($ordered, $dates[$i], $existing[$i] ?? []);
            $days[$i] = $stops;
            $dropped += $drop;
            $usedHours = $usedHours || $hours;
        }

        if ($dropped > 0) {
            $warnings[] = $this->warn('overflow', "$dropped place(s) did not fit in the daily time window (09:30–20:30) and were left out.");
        }
        $unknownHours = collect($days)->flatten(1)->contains(fn ($s) => ! ($s['hours_checked'] ?? false));
        if ($unknownHours) {
            $warnings[] = $this->warn('no_opening_hours', 'Opening hours are not available for some or all suggested places. Check official sources before you go; no stop is guaranteed to be open.');
        }
        if (collect($days)->flatten(1)->count() > 1) {
            $warnings[] = $this->warn('travel_estimates', 'Travel times between stops are rough straight-line estimates, not routes or transit timetables.');
        }

        return ['days' => $days, 'warnings' => $warnings];
    }

    // --- selection ---------------------------------------------------------------------------

    private function usable(array $places, array $exclude): array
    {
        $out = [];
        foreach ($places as $p) {
            if (in_array($p['id'], $exclude, true) || isset($out[$p['id']])) {
                continue;
            }
            if (! Geo::validCoordinates($p['latitude'] ?? null, $p['longitude'] ?? null)) {
                continue; // never plan a stop without real coordinates
            }
            $out[$p['id']] = $p;
        }
        ksort($out);

        return array_values($out);
    }

    /** Drop near-identical entries (same normalised name within 150 m), keeping the more popular one. */
    private function dedupe(array $places): array
    {
        usort($places, fn ($a, $b) => [$b['popularity'] ?? 0, $a['id']] <=> [$a['popularity'] ?? 0, $b['id']]);
        $kept = [];
        foreach ($places as $p) {
            $norm = $this->norm($p['name']);
            foreach ($kept as $k) {
                if ($this->norm($k['name']) === $norm
                    && Geo::haversineKm($k['latitude'], $k['longitude'], $p['latitude'], $p['longitude']) < 0.15) {
                    continue 2;
                }
            }
            $kept[] = $p;
        }

        return $kept;
    }

    private function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s));
    }

    /** @return array{0: list<array>, 1: int} selected places and how many were non-matching backfill */
    private function select(array $candidates, array $interests, int $slots): array
    {
        $scored = [];
        foreach ($candidates as $p) {
            $matches = count(array_intersect($interests, $p['interests'] ?? []));
            $scored[] = $p + ['_matches' => $matches, '_score' => ($p['popularity'] ?? 0) + 30 * $matches];
        }
        usort($scored, fn ($a, $b) => [$b['_score'], $a['id']] <=> [$a['_score'], $b['id']]);

        if (! $interests) {
            return [array_slice($scored, 0, $slots), 0];
        }
        $matching = array_values(array_filter($scored, fn ($p) => $p['_matches'] > 0));
        $rest = array_values(array_filter($scored, fn ($p) => $p['_matches'] === 0));
        $take = array_slice($matching, 0, $slots);
        $backfill = array_slice($rest, 0, max(0, $slots - count($take)));

        return [array_merge($take, $backfill), count($backfill)];
    }

    // --- grouping ----------------------------------------------------------------------------

    /** Geographic grouping: farthest-first seeds, then nearest-seed assignment with day capacity. */
    private function assign(array $selected, array $capacity, array $existing): array
    {
        $dayIdx = array_keys(array_filter($capacity, fn ($c) => $c > 0));
        $result = array_fill_keys(array_keys($capacity), []);
        if (! $dayIdx || ! $selected) {
            return $result;
        }

        // Seeds: first = top-scored place; next = farthest from existing seeds.
        $seeds = [];
        $pool = $selected;
        $seedPlaces = [];
        foreach ($dayIdx as $n => $i) {
            if (! $pool) {
                break;
            }
            if ($n === 0) {
                $pick = 0;
            } else {
                $best = -1.0;
                $pick = 0;
                foreach ($pool as $k => $p) {
                    $d = min(array_map(fn ($s) => Geo::haversineKm($p['latitude'], $p['longitude'], $s[0], $s[1]), $seedPlaces));
                    if ($d > $best + 1e-9) {
                        $best = $d;
                        $pick = $k;
                    }
                }
            }
            $p = $pool[$pick];
            unset($pool[$pick]);
            $pool = array_values($pool);
            $seeds[$i] = [$p['latitude'], $p['longitude']];
            $seedPlaces[] = $seeds[$i];
            $result[$i][] = $p;
        }

        $room = [];
        foreach ($dayIdx as $i) {
            $room[$i] = $capacity[$i] - count($result[$i]);
        }
        foreach ($pool as $p) {
            $order = array_keys($seeds);
            usort($order, function ($a, $b) use ($p, $seeds) {
                $da = Geo::haversineKm($p['latitude'], $p['longitude'], $seeds[$a][0], $seeds[$a][1]);
                $db = Geo::haversineKm($p['latitude'], $p['longitude'], $seeds[$b][0], $seeds[$b][1]);

                return [$da, $a] <=> [$db, $b];
            });
            foreach ($order as $i) {
                if (($room[$i] ?? 0) > 0) {
                    $result[$i][] = $p;
                    $room[$i]--;
                    break;
                }
            }
        }

        return $result;
    }

    /** Nearest-neighbour route order starting nearest the accommodation, else from the top-scored stop. */
    private function order(array $places, ?array $origin): array
    {
        if (count($places) < 2) {
            return $places;
        }
        $remaining = $places;
        if ($origin) {
            usort($remaining, fn ($a, $b) => [Geo::haversineKm($origin[0], $origin[1], $a['latitude'], $a['longitude']), $a['id']]
                <=> [Geo::haversineKm($origin[0], $origin[1], $b['latitude'], $b['longitude']), $b['id']]);
        }
        $route = [array_shift($remaining)];
        while ($remaining) {
            $last = end($route);
            usort($remaining, fn ($a, $b) => [Geo::haversineKm($last['latitude'], $last['longitude'], $a['latitude'], $a['longitude']), $a['id']]
                <=> [Geo::haversineKm($last['latitude'], $last['longitude'], $b['latitude'], $b['longitude']), $b['id']]);
            $route[] = array_shift($remaining);
        }

        return $route;
    }

    // --- scheduling --------------------------------------------------------------------------

    /** Rough straight-line travel estimate in minutes. Walking when short, otherwise a generic transit speed. */
    public static function travelMinutes(array $a, array $b): int
    {
        $km = Geo::haversineKm($a['latitude'], $a['longitude'], $b['latitude'], $b['longitude']) * 1.3;
        $speed = $km <= 2.0 ? 4.5 : 18.0;

        return (int) max(5, ceil($km / $speed * 60));
    }

    /** @return array{0: list<array>, 1: int, 2: bool} stops, dropped count, whether any opening hours were applied */
    private function schedule(array $places, string $date, array $existing): array
    {
        $cursor = self::DAY_START_MIN;
        $prev = null;
        foreach ($existing as $e) {
            $end = $this->toMin($e['start_time'] ?? null) + ($e['duration'] ?? 0);
            if ($e['start_time'] ?? null) {
                $cursor = max($cursor, $end);
            }
            if (isset($e['latitude'], $e['longitude'])) {
                $prev = ['latitude' => $e['latitude'], 'longitude' => $e['longitude']];
            }
        }
        $lunchTaken = $cursor > self::LUNCH_FROM_MIN + 30;
        $weekday = strtolower(CarbonImmutable::parse($date)->format('D'));
        $stops = [];
        $dropped = 0;
        $usedHours = false;

        foreach ($places as $p) {
            $start = $cursor + ($prev ? self::travelMinutes($prev, $p) : 0);
            if (! $lunchTaken && $start >= self::LUNCH_FROM_MIN && $start < self::LUNCH_TO_MIN) {
                $start = self::LUNCH_TO_MIN;
                $lunchTaken = true;
            }
            $duration = (int) ($p['duration'] ?? 60);
            $warnings = [];
            $checked = false;

            if (! empty($p['opening_hours']) && isset($p['opening_hours'][$weekday])) {
                $checked = true;
                $usedHours = true;
                $intervals = $p['opening_hours'][$weekday];
                [$fit, $why] = $this->fitToHours($start, $duration, $intervals);
                if ($fit === null) {
                    $warnings[] = $why;
                } else {
                    $start = $fit;
                }
            } elseif (! empty($p['opening_hours'])) {
                $checked = true;
                $warnings[] = 'Source data lists this place as closed on this weekday.';
            }

            if ($start + $duration > self::DAY_END_MIN) {
                $dropped++;

                continue;
            }

            $stops[] = [
                'place_id' => $p['id'],
                'name' => $p['name'],
                'latitude' => $p['latitude'],
                'longitude' => $p['longitude'],
                'start_time' => $this->fmt($start),
                'duration' => $duration,
                'warnings' => $warnings,
                'hours_checked' => $checked,
            ];
            $cursor = $start + $duration;
            $prev = $p;
        }

        return [$stops, $dropped, $usedHours];
    }

    /** @return array{0: ?int, 1: ?string} adjusted start minute, or [null, reason] when it cannot fit */
    private function fitToHours(int $start, int $duration, array $intervals): array
    {
        foreach ($intervals as [$open, $close]) {
            $o = $this->toMin($open);
            $c = $this->toMin($close);
            $s = max($start, $o);
            if ($s + $duration <= $c) {
                return [$s, null];
            }
        }

        return [null, 'Source data suggests this place may be closed at the scheduled time.'];
    }

    private function toMin(?string $hhmm): int
    {
        if (! $hhmm) {
            return 0;
        }
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    private function fmt(int $min): string
    {
        return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }

    private function warn(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}

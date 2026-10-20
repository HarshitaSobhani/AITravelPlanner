@php
    $t = $trip;
    $fmtMoney = function (?int $minor, string $cur, int $exp) {
        if ($minor === null) { return '—'; }
        return $cur.' '.number_format($minor / (10 ** $exp), $exp);
    };
    $dateLabel = fn ($d) => \Carbon\Carbon::parse($d)->format('D, j M Y');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $t['title'] }} — itinerary</title>
    <style>
        :root { --ink:#2b2a26; --muted:#6d695f; --line:#ddd6c8; --accent:#2f6f6a; --bg:#fbf8f2; }
        * { box-sizing: border-box; }
        body { font-family: Georgia, 'Times New Roman', serif; color: var(--ink); background: var(--bg); margin: 0; line-height: 1.5; }
        main { max-width: 820px; margin: 0 auto; padding: 32px 20px 64px; }
        h1 { font-size: 2rem; margin: 0 0 4px; }
        h2 { font-size: 1.25rem; margin: 32px 0 8px; border-bottom: 2px solid var(--accent); padding-bottom: 4px; color: var(--accent); }
        .meta { color: var(--muted); font-family: system-ui, sans-serif; font-size: .9rem; }
        .notice { font-family: system-ui, sans-serif; font-size: .85rem; background: #fff; border: 1px solid var(--line); border-left: 4px solid #b5651d; padding: 10px 14px; margin: 16px 0; }
        .stop { display: flex; gap: 14px; padding: 10px 0; border-bottom: 1px solid var(--line); page-break-inside: avoid; }
        .time { width: 64px; flex: none; font-family: system-ui, sans-serif; font-weight: 600; color: var(--accent); }
        .name { font-weight: 700; }
        .small { font-family: system-ui, sans-serif; font-size: .85rem; color: var(--muted); }
        table { border-collapse: collapse; width: 100%; font-family: system-ui, sans-serif; font-size: .9rem; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .toolbar { font-family: system-ui, sans-serif; margin-bottom: 16px; display:flex; gap:8px; flex-wrap:wrap; }
        .toolbar a, .toolbar button { font: inherit; padding: 8px 14px; border: 1px solid var(--accent); background: var(--accent); color: #fff; border-radius: 6px; text-decoration: none; cursor: pointer; }
        .toolbar a.secondary { background: transparent; color: var(--accent); }
        footer { margin-top: 40px; font-family: system-ui, sans-serif; font-size: .8rem; color: var(--muted); }
        @media print { body { background: #fff; } .toolbar { display: none; } main { padding: 0; } }
    </style>
</head>
<body>
<main>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print or save as PDF</button>
        <a class="secondary" href="{{ route('trips.show', $t['id']) }}">Back to trip</a>
        <a class="secondary" href="{{ route('trips.export', ['trip' => $t['id'], 'notes' => $includeNotes ? 0 : 1, 'budget' => $includeBudget ? 1 : 0]) }}">{{ $includeNotes ? 'Hide notes' : 'Show notes' }}</a>
    </div>

    <h1>{{ $t['title'] }}</h1>
    <p class="meta">
        {{ $t['destination']['name'] }}{{ $t['destination']['country_name'] ? ', '.$t['destination']['country_name'] : '' }}
        · {{ $dateLabel($t['start_date']) }} – {{ $dateLabel($t['end_date']) }}
        · {{ $t['travelers'] }} {{ $t['travelers'] === 1 ? 'traveller' : 'travellers' }}
        · {{ ucfirst($t['pace']) }} pace
    </p>

    <p class="notice"><strong>Suggested schedule.</strong> Times and durations are suggestions you can change. Travel times are straight-line estimates.
        Opening hours, prices and availability are not verified by RoamWise — confirm with each venue. Nothing in this document is a booking or confirmation.</p>

    @foreach ($t['warnings'] as $w)
        <p class="small">• {{ $w['message'] }}</p>
    @endforeach

    @if ($includeNotes && $t['notes'])
        <h2>Trip notes</h2>
        <p>{{ $t['notes'] }}</p>
    @endif

    @foreach ($days as $day)
        <h2>Day {{ $day['position'] + 1 }} — {{ $dateLabel($day['date']) }}{{ $day['title'] ? ': '.$day['title'] : '' }}</h2>
        @if ($includeNotes && $day['notes'])<p class="small">{{ $day['notes'] }}</p>@endif
        @forelse ($day['stops'] as $stop)
            <div class="stop">
                <div class="time">{{ $stop['start_time'] ?? '—' }}</div>
                <div>
                    <div class="name">{{ $stop['name'] }}</div>
                    <div class="small">
                        {{ $stop['duration_minutes'] }} min
                        @if($stop['category']) · {{ ucfirst($stop['category']) }}@endif
                        @if($stop['neighbourhood']) · {{ $stop['neighbourhood'] }}@endif
                        @if($stop['latitude'] !== null) · {{ number_format($stop['latitude'], 5) }}, {{ number_format($stop['longitude'], 5) }}@endif
                    </div>
                    @if ($includeNotes && $stop['notes'])<div>{{ $stop['notes'] }}</div>@endif
                    @foreach ($stop['warnings'] as $w)<div class="small">⚠ {{ $w }}</div>@endforeach
                </div>
            </div>
        @empty
            <p class="small">No stops planned for this day.</p>
        @endforelse
    @endforeach

    @if ($includeBudget)
        <h2>Budget</h2>
        @php $b = $budget; @endphp
        <table>
            <thead><tr><th>Category</th><th class="num">Estimated</th><th class="num">Actual</th></tr></thead>
            <tbody>
            @foreach ($b['categories'] as $c)
                @if ($c['count'] > 0)
                    <tr><td>{{ $c['name'] }}</td><td class="num">{{ $fmtMoney($c['estimated_minor'], $b['currency'], $b['exponent']) }}</td><td class="num">{{ $c['actual_minor'] > 0 ? $fmtMoney($c['actual_minor'], $b['currency'], $b['exponent']) : '—' }}</td></tr>
                @endif
            @endforeach
            </tbody>
            <tfoot>
                <tr><th>Total (your estimates)</th><th class="num">{{ $fmtMoney($b['estimated_minor'], $b['currency'], $b['exponent']) }}</th><th class="num">{{ $fmtMoney($b['actual_minor'], $b['currency'], $b['exponent']) }}</th></tr>
                <tr><td>Trip budget</td><td class="num" colspan="2">{{ $fmtMoney($b['budget_minor'], $b['currency'], $b['exponent']) }}</td></tr>
                <tr><td>Remaining vs. estimates</td><td class="num" colspan="2">{{ $fmtMoney($b['remaining_minor'], $b['currency'], $b['exponent']) }}</td></tr>
            </tfoot>
        </table>
        <p class="small">All amounts are figures you entered. Foreign-currency items use the exchange rates you entered; RoamWise does not supply live rates or prices.</p>
    @endif

    <footer>
        Generated {{ now()->format('j M Y H:i') }} from your saved itinerary.
        Map and place data: OpenStreetMap contributors where noted{{ $t['source_attribution'] ? ' · '.$t['source_attribution'] : '' }}.
    </footer>
</main>
</body>
</html>

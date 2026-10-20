<?php

return [
    'weather' => [
        'provider' => env('WEATHER_PROVIDER', 'open-meteo'), // open-meteo | none
        'forecast_url' => env('OPEN_METEO_FORECAST_URL', 'https://api.open-meteo.com/v1/forecast'),
        'archive_url' => env('OPEN_METEO_ARCHIVE_URL', 'https://archive-api.open-meteo.com/v1/archive'),
        'timeout' => (int) env('WEATHER_TIMEOUT', 8),
        'cache_minutes' => (int) env('WEATHER_CACHE_MINUTES', 60),
        'forecast_horizon_days' => 16,
    ],

    'geocoding' => [
        'provider' => env('GEOCODING_PROVIDER', 'open-meteo'), // open-meteo | none
        'url' => env('OPEN_METEO_GEOCODING_URL', 'https://geocoding-api.open-meteo.com/v1/search'),
        'timeout' => (int) env('GEOCODING_TIMEOUT', 6),
        'cache_minutes' => (int) env('GEOCODING_CACHE_MINUTES', 1440),
    ],

    'routing' => [
        // Leave ROUTING_OSRM_URL empty to disable route geometry. Do not point at the public demo
        // server for production traffic; run your own OSRM or use a provider whose terms allow it.
        'osrm_url' => env('ROUTING_OSRM_URL'),
        'timeout' => (int) env('ROUTING_TIMEOUT', 8),
        'cache_minutes' => (int) env('ROUTING_CACHE_MINUTES', 1440),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'none'), // none | anthropic | openai-compatible
        'timeout' => (int) env('AI_TIMEOUT', 25),
        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),
        ],
        'openai' => [
            'key' => env('OPENAI_COMPATIBLE_API_KEY'),
            'model' => env('OPENAI_COMPATIBLE_MODEL'),
            'url' => env('OPENAI_COMPATIBLE_URL', 'https://api.openai.com/v1/chat/completions'),
        ],
    ],

    'map' => [
        'tile_url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('MAP_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors'),
    ],
];

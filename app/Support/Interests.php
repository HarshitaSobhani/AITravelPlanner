<?php

namespace App\Support;

final class Interests
{
    public const ALL = [
        'food' => 'Food & drink',
        'architecture' => 'Architecture',
        'nature' => 'Nature & parks',
        'history' => 'History',
        'shopping' => 'Shopping',
        'literature' => 'Literature',
        'museums' => 'Museums & art',
        'nightlife' => 'Nightlife',
        'relaxation' => 'Relaxation',
    ];

    public const PACES = [
        'relaxed' => ['label' => 'Relaxed', 'stops_per_day' => 2],
        'balanced' => ['label' => 'Balanced', 'stops_per_day' => 3],
        'packed' => ['label' => 'Packed', 'stops_per_day' => 5],
    ];

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function paceKeys(): array
    {
        return array_keys(self::PACES);
    }
}

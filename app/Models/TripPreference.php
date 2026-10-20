<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripPreference extends Model
{
    protected $fillable = [
        'interests', 'accessibility', 'dietary', 'accommodation',
        'accommodation_latitude', 'accommodation_longitude',
    ];

    protected function casts(): array
    {
        return ['interests' => 'array', 'accommodation_latitude' => 'float', 'accommodation_longitude' => 'float'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}

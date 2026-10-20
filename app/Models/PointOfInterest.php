<?php

namespace App\Models;

use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointOfInterest extends Model
{
    use HasFactory;

    protected $table = 'points_of_interest';

    protected $fillable = [
        'destination_id', 'data_source_id', 'slug', 'name', 'category', 'interests', 'description',
        'latitude', 'longitude', 'neighbourhood', 'website', 'suggested_duration_minutes', 'popularity',
        'opening_hours', 'accessibility_note', 'osm_ref', 'coordinates_verified_at', 'image_url', 'image_attribution',
    ];

    protected function casts(): array
    {
        return [
            'interests' => 'array',
            'opening_hours' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'coordinates_verified_at' => 'datetime',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function hasValidCoordinates(): bool
    {
        return Geo::validCoordinates($this->latitude, $this->longitude);
    }

    /** Safe payload for the client. */
    public function toMapArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category,
            'interests' => $this->interests ?? [],
            'description' => $this->description,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'neighbourhood' => $this->neighbourhood,
            'website' => Geo::safeUrl($this->website),
            'duration' => $this->suggested_duration_minutes,
            'has_opening_hours' => ! empty($this->opening_hours),
            'accessibility_note' => $this->accessibility_note,
            'verified' => $this->coordinates_verified_at !== null,
        ];
    }
}

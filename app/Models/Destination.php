<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id', 'data_source_id', 'slug', 'name', 'type', 'country_code', 'country_name',
        'latitude', 'longitude', 'timezone', 'currency_code', 'summary', 'best_season',
        'image_url', 'image_attribution', 'external_ref',
    ];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function places(): HasMany
    {
        return $this->hasMany(PointOfInterest::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(fn ($q) => $q->where('name', 'like', $like)
            ->orWhere('country_name', 'like', $like)
            ->orWhere('summary', 'like', $like));
    }
}

<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Trip extends Model
{
    use HasFactory;

    // user_id is deliberately NOT fillable: ownership is always assigned from the session.
    protected $fillable = [
        'destination_id', 'title', 'start_date', 'end_date', 'travelers', 'currency',
        'budget_minor', 'pace', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'budget_minor' => 'integer',
            'travelers' => 'integer',
            'plan_warnings' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(TripPreference::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(ItineraryDay::class)->orderBy('position');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(ItineraryStop::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(TripShare::class);
    }

    public function dayCount(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    /** upcoming | in_progress | completed, derived from dates only. */
    public function status(?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();
        if ($today->lt($this->start_date)) {
            return 'upcoming';
        }

        return $today->gt($this->end_date) ? 'completed' : 'in_progress';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TripShare extends Model
{
    protected $fillable = ['include_budget_totals'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return [
            'include_budget_totals' => 'boolean',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'token_encrypted' => 'encrypted',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Create a share with a fresh 256-bit random token. */
    public static function issue(Trip $trip, bool $includeBudget = false): self
    {
        $token = Str::random(48);
        $share = new self(['include_budget_totals' => $includeBudget]);
        $share->trip_id = $trip->id;
        $share->token_hash = self::hashToken($token);
        $share->token_encrypted = $token;
        $share->save();

        return $share;
    }

    public static function findActiveByToken(string $token): ?self
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }

        return self::query()->where('token_hash', self::hashToken($token))->whereNull('revoked_at')->first();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}

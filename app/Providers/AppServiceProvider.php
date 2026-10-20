<?php

namespace App\Providers;

use App\Services\Ai\HttpNarrator;
use App\Services\Ai\ItineraryNarrator;
use App\Services\Ai\NullNarrator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ItineraryNarrator::class, function () {
            $driver = config('roamwise.ai.provider');

            return in_array($driver, ['anthropic', 'openai-compatible'], true) ? new HttpNarrator($driver) : new NullNarrator;
        });
    }

    public function boot(): void
    {
        $user = fn (Request $r) => $r->user()?->id ?: $r->ip();

        RateLimiter::for('plan', fn (Request $r) => Limit::perMinute(10)->by($user($r)));
        RateLimiter::for('lookup', fn (Request $r) => Limit::perMinute(20)->by($user($r)));
        RateLimiter::for('external', fn (Request $r) => Limit::perMinute(30)->by($user($r)));
        RateLimiter::for('ai', fn (Request $r) => Limit::perMinute(5)->by($user($r)));
        RateLimiter::for('share-view', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
    }
}

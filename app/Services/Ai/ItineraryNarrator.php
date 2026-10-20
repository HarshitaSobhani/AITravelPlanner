<?php

namespace App\Services\Ai;

/**
 * Optional provider interface. A narrator may only describe a plan that already exists; it never
 * creates, removes or reorders stops, so the deterministic planner remains the source of truth.
 */
interface ItineraryNarrator
{
    public function available(): bool;

    /** Returns plain text, or null when the provider is unavailable or returns unusable output. */
    public function summarize(array $plan): ?string;
}

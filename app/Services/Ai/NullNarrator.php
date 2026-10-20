<?php

namespace App\Services\Ai;

class NullNarrator implements ItineraryNarrator
{
    public function available(): bool
    {
        return false;
    }

    public function summarize(array $plan): ?string
    {
        return null;
    }
}

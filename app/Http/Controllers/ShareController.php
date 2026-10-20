<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripShare;
use App\Services\Trips\TripPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShareController extends Controller
{
    public function store(Request $request, Trip $trip): RedirectResponse
    {
        $this->authorize('update', $trip);
        $data = $request->validate(['include_budget_totals' => ['boolean']]);
        TripShare::issue($trip, (bool) ($data['include_budget_totals'] ?? false));

        return back()->with('success', 'Share link created. Anyone with the link can view this itinerary.');
    }

    public function destroy(TripShare $share): RedirectResponse
    {
        $this->authorize('update', $share->trip);
        $share->forceFill(['revoked_at' => now()])->save();

        return back()->with('success', 'Link revoked. It no longer works.');
    }

    /** Public, read-only. Unknown, malformed and revoked tokens are indistinguishable 404s. */
    public function show(string $token, TripPresenter $presenter): Response
    {
        $share = TripShare::findActiveByToken($token);
        abort_if($share === null, 404);
        $share->forceFill(['last_viewed_at' => now()])->saveQuietly();

        return Inertia::render('Shared/Show', $presenter->shared($share->trip, $share->include_budget_totals) + [
            'map' => ['tile_url' => config('roamwise.map.tile_url'), 'attribution' => config('roamwise.map.attribution')],
        ])->withViewData(['noindex' => true]);
    }
}

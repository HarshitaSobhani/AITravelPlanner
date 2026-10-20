<?php

namespace App\Http\Controllers;

use App\Http\Requests\StopRequest;
use App\Models\ItineraryDay;
use App\Models\ItineraryStop;
use App\Models\Trip;
use App\Services\Planning\ItineraryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StopController extends Controller
{
    public function __construct(private readonly ItineraryService $itinerary) {}

    public function store(StopRequest $request, Trip $trip): RedirectResponse
    {
        $this->authorize('update', $trip);
        $data = $request->validated();
        // The day id comes from the client: resolve it inside this trip only (blocks cross-trip writes).
        $day = $trip->days()->whereKey($data['itinerary_day_id'])->firstOrFail();

        if (! empty($data['point_of_interest_id'])
            && $trip->stops()->where('point_of_interest_id', $data['point_of_interest_id'])->exists()) {
            return back()->with('error', 'That place is already in this trip.');
        }

        $this->itinerary->addStop($trip, $day, $data);

        return back()->with('success', 'Stop added.');
    }

    public function update(StopRequest $request, ItineraryStop $stop): RedirectResponse
    {
        $this->authorize('update', $stop->trip);
        $this->itinerary->updateStop($stop, $request->validated());

        return back()->with('success', 'Stop saved.');
    }

    public function destroy(ItineraryStop $stop): RedirectResponse
    {
        $this->authorize('update', $stop->trip);
        $this->itinerary->removeStop($stop);

        return back()->with('success', 'Stop removed.');
    }

    /** Move within a day (position) and/or to another day of the same trip. */
    public function move(Request $request, ItineraryStop $stop): RedirectResponse
    {
        $this->authorize('update', $stop->trip);
        $data = $request->validate([
            'itinerary_day_id' => ['required', 'integer'],
            'position' => ['required', 'integer', 'min:0', 'max:500'],
        ]);
        $day = ItineraryDay::where('trip_id', $stop->trip_id)->whereKey($data['itinerary_day_id'])->firstOrFail();
        $this->itinerary->moveStop($stop, $day, $data['position']);

        return back()->with('success', 'Stop moved.');
    }
}

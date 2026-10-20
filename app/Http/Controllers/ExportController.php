<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Services\Trips\TripPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    /** Printable page (use the browser's "Save as PDF"). Always built from the database, never client state. */
    public function __invoke(Request $request, Trip $trip, TripPresenter $presenter): View
    {
        $this->authorize('view', $trip);
        $data = $presenter->owner($trip);

        return view('trips.export', $data + [
            'includeNotes' => $request->boolean('notes', true),
            'includeBudget' => $request->boolean('budget', true),
        ]);
    }
}

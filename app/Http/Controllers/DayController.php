<?php

namespace App\Http\Controllers;

use App\Models\ItineraryDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DayController extends Controller
{
    public function update(Request $request, ItineraryDay $day): RedirectResponse
    {
        $this->authorize('update', $day->trip);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $day->update($data);

        return back()->with('success', 'Day saved.');
    }
}

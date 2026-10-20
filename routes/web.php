<?php

use App\Http\Controllers\DayController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\StopController;
use App\Http\Controllers\TripController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Public discovery
Route::get('destinations', [DestinationController::class, 'index'])->name('destinations.index');
Route::get('destinations/{destination:slug}', [DestinationController::class, 'show'])->name('destinations.show');

// Public read-only shared itinerary
Route::get('s/{token}', [ShareController::class, 'show'])->middleware('throttle:share-view')->name('shared.show');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', 'trips')->name('dashboard');

    Route::get('saved', [DestinationController::class, 'saved'])->name('saved');
    Route::post('destinations/{destination:slug}/save', [DestinationController::class, 'toggleSave'])->name('destinations.save');
    Route::post('places/{place}/save', [DestinationController::class, 'togglePlace'])->name('places.save');
    Route::get('lookup/destinations', [DestinationController::class, 'lookup'])->middleware('throttle:lookup')->name('destinations.lookup');
    Route::post('lookup/destinations/import', [DestinationController::class, 'import'])->middleware('throttle:lookup')->name('destinations.import');

    Route::resource('trips', TripController::class)->except(['edit']);
    Route::post('trips/{trip}/regenerate', [TripController::class, 'regenerate'])->middleware('throttle:plan')->name('trips.regenerate');
    Route::post('trips/{trip}/summary', [TripController::class, 'summary'])->middleware('throttle:ai')->name('trips.summary');
    Route::get('trips/{trip}/weather', [TripController::class, 'weather'])->middleware('throttle:external')->name('trips.weather');
    Route::get('trips/{trip}/days/{day}/route', [TripController::class, 'route'])->middleware('throttle:external')->name('trips.route');
    Route::get('trips/{trip}/export', ExportController::class)->name('trips.export');

    Route::post('trips/{trip}/stops', [StopController::class, 'store'])->name('stops.store');
    Route::patch('stops/{stop}', [StopController::class, 'update'])->name('stops.update');
    Route::delete('stops/{stop}', [StopController::class, 'destroy'])->name('stops.destroy');
    Route::post('stops/{stop}/move', [StopController::class, 'move'])->name('stops.move');
    Route::patch('days/{day}', [DayController::class, 'update'])->name('days.update');

    Route::post('trips/{trip}/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::patch('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

    Route::post('trips/{trip}/shares', [ShareController::class, 'store'])->name('shares.store');
    Route::delete('shares/{share}', [ShareController::class, 'destroy'])->name('shares.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

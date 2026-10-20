<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Models\Trip;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;

class ExpenseController extends Controller
{
    public function store(ExpenseRequest $request, Trip $trip): RedirectResponse
    {
        $this->authorize('update', $trip);
        $expense = new Expense($this->attributes($request->validated(), $trip));
        $expense->trip_id = $trip->id;
        $expense->save();

        return back()->with('success', 'Expense added.');
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $trip = $expense->trip;
        $this->authorize('update', $trip);
        $expense->update($this->attributes($request->validated(), $trip));

        return back()->with('success', 'Expense updated.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('update', $expense->trip);
        $expense->delete();

        return back()->with('success', 'Expense removed.');
    }

    private function attributes(array $d, Trip $trip): array
    {
        $same = $d['currency'] === $trip->currency;

        return [
            'expense_category_id' => $d['expense_category_id'],
            'description' => $d['description'],
            'currency' => $d['currency'],
            'estimated_minor' => Money::toMinor($d['estimated'], $d['currency']),
            'actual_minor' => isset($d['actual']) ? Money::toMinor($d['actual'], $d['currency']) : null,
            'manual_rate' => $same ? null : $d['manual_rate'],
            'rate_note' => $same ? null : ($d['rate_note'] ?? null),
        ];
    }
}

<?php

namespace App\Services\Trips;

use App\Models\ExpenseCategory;
use App\Models\Trip;
use App\Support\Money;
use InvalidArgumentException;

/** Budget math in integer minor units of the trip currency. Conversions use only user-entered rates. */
class BudgetService
{
    public function summarize(Trip $trip): array
    {
        $trip->loadMissing('expenses.category');
        $cats = ExpenseCategory::orderBy('sort_order')->get();
        $byCat = [];
        foreach ($cats as $c) {
            $byCat[$c->id] = ['key' => $c->key, 'name' => $c->name, 'estimated_minor' => 0, 'actual_minor' => 0, 'count' => 0];
        }

        $est = 0;
        $act = 0;
        $unconverted = 0;
        foreach ($trip->expenses as $e) {
            try {
                $estimated = Money::convertMinor($e->estimated_minor, $e->currency, $trip->currency, $e->manual_rate);
                $actual = $e->actual_minor === null ? null : Money::convertMinor($e->actual_minor, $e->currency, $trip->currency, $e->manual_rate);
            } catch (InvalidArgumentException) {
                $unconverted++; // foreign-currency item without a usable manual rate: excluded, and reported

                continue;
            }
            $est += $estimated;
            $act += $actual ?? 0;
            $byCat[$e->expense_category_id]['estimated_minor'] += $estimated;
            $byCat[$e->expense_category_id]['actual_minor'] += $actual ?? 0;
            $byCat[$e->expense_category_id]['count']++;
        }

        $budget = $trip->budget_minor;
        $perTraveler = $trip->travelers > 0 ? intdiv($est, $trip->travelers) : null;

        return [
            'currency' => $trip->currency,
            'exponent' => Money::exponent($trip->currency),
            'budget_minor' => $budget,
            'estimated_minor' => $est,
            'actual_minor' => $act,
            'estimated_per_traveler_minor' => $perTraveler,
            'remaining_minor' => $budget === null ? null : $budget - $est,
            'remaining_after_actuals_minor' => $budget === null ? null : $budget - $act,
            'categories' => array_values($byCat),
            'excluded_without_rate' => $unconverted,
        ];
    }
}

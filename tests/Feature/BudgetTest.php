<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Trips\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use BuildsTrips, RefreshDatabase;

    private function trip(array $over = []): array
    {
        $user = User::factory()->create();
        $trip = $this->createTripVia($user, $this->destinationWithPlaces(4), $over + ['travelers' => 3, 'budget' => '1000']);

        return [$user, $trip];
    }

    private function cat(string $key): int
    {
        return ExpenseCategory::where('key', $key)->value('id');
    }

    public function test_create_update_delete_expense(): void
    {
        [$user, $trip] = $this->trip();
        $this->actingAs($user)->post("/trips/{$trip->id}/expenses", [
            'expense_category_id' => $this->cat('food'), 'description' => 'Dinner', 'currency' => 'EUR', 'estimated' => '120.50',
        ])->assertSessionHasNoErrors();
        $e = $trip->expenses()->first();
        $this->assertSame(12050, $e->estimated_minor);
        $this->assertNull($e->actual_minor);

        $this->actingAs($user)->patch("/expenses/{$e->id}", [
            'expense_category_id' => $this->cat('food'), 'description' => 'Dinner', 'currency' => 'EUR', 'estimated' => '120.50', 'actual' => '131.25',
        ]);
        $this->assertSame(13125, $e->fresh()->actual_minor);

        $this->actingAs($user)->delete("/expenses/{$e->id}");
        $this->assertSame(0, $trip->expenses()->count());
    }

    public function test_totals_per_traveller_remaining_and_categories(): void
    {
        [$user, $trip] = $this->trip();
        Expense::factory()->create(['trip_id' => $trip->id, 'expense_category_id' => $this->cat('food'), 'estimated_minor' => 30000, 'actual_minor' => 25000]);
        Expense::factory()->create(['trip_id' => $trip->id, 'expense_category_id' => $this->cat('accommodation'), 'estimated_minor' => 45050, 'actual_minor' => null]);

        $b = app(BudgetService::class)->summarize($trip->fresh());
        $this->assertSame(75050, $b['estimated_minor']);
        $this->assertSame(25000, $b['actual_minor']);
        $this->assertSame(25016, $b['estimated_per_traveler_minor']);
        $this->assertSame(100000 - 75050, $b['remaining_minor']);
        $byKey = collect($b['categories'])->keyBy('key');
        $this->assertSame(30000, $byKey['food']['estimated_minor']);
        $this->assertSame(25000, $byKey['food']['actual_minor']);
        $this->assertSame(45050, $byKey['accommodation']['estimated_minor']);
        $this->assertSame(0, $byKey['shopping']['estimated_minor']);
    }

    public function test_remaining_can_go_negative_and_is_null_without_budget(): void
    {
        [, $trip] = $this->trip(['budget' => null]);
        Expense::factory()->create(['trip_id' => $trip->id, 'estimated_minor' => 1000]);
        $this->assertNull(app(BudgetService::class)->summarize($trip->fresh())['remaining_minor']);

        [, $trip2] = $this->trip(['budget' => '5']);
        Expense::factory()->create(['trip_id' => $trip2->id, 'estimated_minor' => 1000]);
        $this->assertSame(-500, app(BudgetService::class)->summarize($trip2->fresh())['remaining_minor']);
    }

    public function test_foreign_currency_requires_an_explicit_rate(): void
    {
        [$user, $trip] = $this->trip();
        $this->actingAs($user)->post("/trips/{$trip->id}/expenses", [
            'expense_category_id' => $this->cat('shopping'), 'description' => 'Souvenirs', 'currency' => 'USD', 'estimated' => '50',
        ])->assertSessionHasErrors('manual_rate');
        $this->assertSame(0, $trip->expenses()->count());

        $this->actingAs($user)->post("/trips/{$trip->id}/expenses", [
            'expense_category_id' => $this->cat('shopping'), 'description' => 'Souvenirs', 'currency' => 'USD', 'estimated' => '50',
            'manual_rate' => '0.9', 'rate_note' => 'card statement 1 May',
        ])->assertSessionHasNoErrors();

        $b = app(BudgetService::class)->summarize($trip->fresh());
        $this->assertSame(4500, $b['estimated_minor']); // 50.00 USD * 0.9 = 45.00 EUR
        $this->assertSame(0, $b['excluded_without_rate']);
    }

    public function test_foreign_item_missing_a_rate_is_excluded_and_reported_not_guessed(): void
    {
        [, $trip] = $this->trip();
        Expense::factory()->create(['trip_id' => $trip->id, 'currency' => 'GBP', 'estimated_minor' => 9999, 'manual_rate' => null]);
        $b = app(BudgetService::class)->summarize($trip->fresh());
        $this->assertSame(0, $b['estimated_minor']);
        $this->assertSame(1, $b['excluded_without_rate']);
    }

    public function test_zero_decimal_currency_budget(): void
    {
        $user = User::factory()->create();
        $trip = $this->createTripVia($user, $this->destinationWithPlaces(3), ['currency' => 'JPY', 'budget' => '150000']);
        $this->assertSame(150000, $trip->budget_minor);
        $this->actingAs($user)->post("/trips/{$trip->id}/expenses", [
            'expense_category_id' => $this->cat('transport'), 'description' => 'Rail pass', 'currency' => 'JPY', 'estimated' => '50000',
        ]);
        $this->assertSame(50000, app(BudgetService::class)->summarize($trip->fresh())['estimated_minor']);
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        [$user, $trip] = $this->trip();
        foreach (['-5', 'abc', '1.234', '1e5', ''] as $bad) {
            $this->actingAs($user)->post("/trips/{$trip->id}/expenses", [
                'expense_category_id' => $this->cat('food'), 'description' => 'x', 'currency' => 'EUR', 'estimated' => $bad,
            ])->assertSessionHasErrors('estimated');
        }
        $this->assertSame(0, $trip->expenses()->count());
    }
}

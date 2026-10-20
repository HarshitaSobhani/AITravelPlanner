<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'expense_category_id' => fn () => ExpenseCategory::firstOrCreate(['key' => 'food'], ['name' => 'Food', 'sort_order' => 2])->id,
            'description' => fake()->words(2, true),
            'currency' => 'EUR',
            'estimated_minor' => 5000,
        ];
    }
}

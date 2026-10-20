<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public const CATEGORIES = [
        'accommodation' => 'Accommodation',
        'food' => 'Food & drink',
        'transport' => 'Local transport',
        'activities' => 'Activities & admission',
        'shopping' => 'Shopping',
        'other' => 'Other',
    ];

    public function run(): void
    {
        $i = 0;
        foreach (self::CATEGORIES as $key => $name) {
            ExpenseCategory::updateOrCreate(['key' => $key], ['name' => $name, 'sort_order' => $i++]);
        }
    }
}

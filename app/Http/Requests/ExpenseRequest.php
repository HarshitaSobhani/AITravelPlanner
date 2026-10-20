<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['actual', 'manual_rate', 'rate_note'] as $k) {
            if ($this->has($k) && is_string($this->input($k)) && trim($this->input($k)) === '') {
                $this->merge([$k => null]);
            }
        }
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->input('currency'))]);
        }
    }

    public function rules(): array
    {
        $trip = $this->route('trip') ?? $this->route('expense')?->trip;
        $foreign = $this->input('currency') && $trip && $this->input('currency') !== $trip->currency;

        return [
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'description' => ['required', 'string', 'max:160'],
            'currency' => ['required', Rule::in(Money::CURRENCIES)],
            'estimated' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'actual' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            // Without a user-entered rate, foreign-currency amounts cannot be added to totals. We never assume one.
            'manual_rate' => [$foreign ? 'required' : 'nullable', 'regex:/^\d{1,8}(\.\d{1,8})?$/', 'gt:0'],
            'rate_note' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function messages(): array
    {
        return [
            'manual_rate.required' => 'Enter the exchange rate you want to use (1 unit of this currency = ? trip currency). RoamWise does not assume rates.',
            'estimated.regex' => 'Enter an amount like 25 or 25.50.',
            'actual.regex' => 'Enter an amount like 25 or 25.50.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Support\Interests;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TripRequest extends FormRequest
{
    public const MAX_DAYS = 30;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['accessibility', 'dietary', 'accommodation', 'notes', 'budget', 'title'] as $k) {
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
        $creating = $this->isMethod('POST');

        return [
            'destination_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:destinations,id'],
            'title' => ['nullable', 'string', 'max:120'],
            'start_date' => ['required', 'date_format:Y-m-d', $creating ? 'after_or_equal:today' : 'date'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:'.$this->maxEnd()],
            'travelers' => ['required', 'integer', 'min:1', 'max:50'],
            'currency' => ['required', Rule::in(Money::CURRENCIES)],
            'budget' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'pace' => ['required', Rule::in(Interests::paceKeys())],
            'interests' => ['nullable', 'array', 'max:'.count(Interests::ALL)],
            'interests.*' => ['string', Rule::in(Interests::keys())],
            'accessibility' => ['nullable', 'string', 'max:500'],
            'dietary' => ['nullable', 'string', 'max:500'],
            'accommodation' => ['nullable', 'string', 'max:160'],
            'accommodation_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:accommodation_longitude'],
            'accommodation_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:accommodation_latitude'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function maxEnd(): string
    {
        $start = $this->input('start_date');
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', (string) $start)->addDays(self::MAX_DAYS - 1)->toDateString();
        } catch (\Throwable) {
            return '9999-12-31';
        }
    }

    public function messages(): array
    {
        return [
            'end_date.before_or_equal' => 'Trips can be at most '.self::MAX_DAYS.' days long.',
            'start_date.after_or_equal' => 'The start date cannot be in the past.',
            'budget.regex' => 'Enter a budget as a number, for example 1500 or 1500.50.',
        ];
    }
}

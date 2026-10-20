<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'itinerary_day_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'point_of_interest_id' => ['nullable', 'integer', 'exists:points_of_interest,id', $creating ? 'required_without:name' : 'prohibited'],
            'name' => ['nullable', 'string', 'max:160', $creating ? 'required_without:point_of_interest_id' : 'sometimes'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

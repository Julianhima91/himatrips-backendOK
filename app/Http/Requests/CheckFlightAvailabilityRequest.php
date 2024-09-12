<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckFlightAvailabilityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'package_config_id' => ['required', 'exists:package_configs,id'],
            'from_date' => ['required', 'date', 'after_or_equal:today'],
            'to_date' => ['required', 'date', 'after:from_date'],
            'is_direct_flight' => ['required', 'boolean'],
            'airline_id' => ['nullable', 'exists:airlines,id'],
        ];
    }
}

<?php

namespace App\Http\Requests\Livesearch;

use Illuminate\Foundation\Http\FormRequest;

class LivesearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nights' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date', 'date_format:Y-m-d'],
            'origin_id' => ['required', 'integer', 'exists:origins,id'],
            'destination_id' => ['required', 'integer', 'exists:destinations,id'],
            'adults' => ['required', 'integer', 'min:1', 'max:5'],
            'children' => ['required', 'integer', 'max:5'],
            'infants' => ['required', 'integer', 'max:5'],
            'rooms' => ['required', 'integer'],
        ];
    }
}

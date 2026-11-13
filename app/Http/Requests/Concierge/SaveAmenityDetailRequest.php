<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveAmenityDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'location' => ['nullable', 'string', 'max:200'],
            'opening_hours' => ['nullable', 'string', 'max:300'],
            'fee_information' => ['nullable', 'string', 'max:300'],
            'reservation_required' => ['required', 'boolean'],
            'reservation_instructions' => ['nullable', 'string', 'max:500'],
            'guest_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

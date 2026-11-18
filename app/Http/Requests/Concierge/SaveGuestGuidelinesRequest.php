<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveGuestGuidelinesRequest extends FormRequest
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
            'arrival_instructions' => ['nullable', 'string', 'max:1000'],
            'departure_instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

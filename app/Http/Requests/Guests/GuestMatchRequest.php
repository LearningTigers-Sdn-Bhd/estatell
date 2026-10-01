<?php

namespace App\Http\Requests\Guests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GuestMatchRequest extends FormRequest
{
    /**
     * The `can:guests.view` route middleware authorizes this request.
     */
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
            'name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'id_numbers' => ['nullable', 'array', 'max:5'],
            'id_numbers.*' => ['string', 'max:40'],
            'exclude_guest_id' => ['nullable', 'integer'],
        ];
    }
}

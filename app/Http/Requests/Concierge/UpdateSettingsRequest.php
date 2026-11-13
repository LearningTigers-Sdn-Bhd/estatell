<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
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
            'walk_in_enabled' => ['required', 'boolean'],
            'refund_requests_enabled' => ['required', 'boolean'],
            'location_check_enabled' => ['sometimes', 'boolean'],
            'house_rules' => ['nullable', 'string', 'max:2000'],
            'room_policy' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'payment_policy' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'other_policies' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

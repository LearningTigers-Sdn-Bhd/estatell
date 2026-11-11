<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The property's own name and description of one place on the Nearby places tab. The route's `can:` middleware checks `booking-website.manage`.
 */
class UpdateNearbyPlaceRequest extends FormRequest
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
            'display_name' => ['nullable', 'string', 'max:255', 'regex:/[\p{L}\p{N}]/u'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.regex' => __('Use at least one letter or number.'),
        ];
    }

    public function displayName(): ?string
    {
        $displayName = trim((string) $this->validated('display_name'));

        return $displayName === '' ? null : $displayName;
    }

    public function description(): ?string
    {
        $description = trim((string) $this->validated('description'));

        return $description === '' ? null : $description;
    }
}

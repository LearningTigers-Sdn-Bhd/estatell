<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The description of one place on the Content tab. The route's `can:` middleware checks `booking-website.manage`.
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
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function description(): ?string
    {
        $description = trim((string) $this->validated('description'));

        return $description === '' ? null : $description;
    }
}

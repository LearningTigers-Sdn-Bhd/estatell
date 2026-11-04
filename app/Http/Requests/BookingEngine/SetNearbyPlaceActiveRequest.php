<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The switch of one place on the Nearby places tab. The route's `can:` middleware checks `booking-website.manage`.
 */
class SetNearbyPlaceActiveRequest extends FormRequest
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
            'active' => ['required', 'boolean'],
        ];
    }
}

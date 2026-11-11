<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The "add a place" form on the Nearby places tab and in the admin dialog. Each route's `can:` middleware authorizes it.
 */
class AddNearbyPlaceRequest extends FormRequest
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
            'google_maps_url' => ['required', 'url:https', 'max:2048'],
        ];
    }
}

<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The new order of a property's places. The route's `can:` middleware checks `booking-website.manage`.
 */
class ReorderNearbyPlacesRequest extends FormRequest
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
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function orderedIds(): array
    {
        return array_map('intval', array_values($this->validated('order')));
    }
}

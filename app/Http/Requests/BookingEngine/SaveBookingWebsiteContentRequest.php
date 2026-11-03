<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Content tab form. The route's `can:` middleware checks `booking-website.manage`.
 */
class SaveBookingWebsiteContentRequest extends FormRequest
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
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function amenityIds(): array
    {
        return array_map('intval', array_values($this->validated('amenity_ids') ?? []));
    }
}

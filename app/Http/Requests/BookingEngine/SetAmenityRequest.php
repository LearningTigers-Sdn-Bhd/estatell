<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One amenity switch on the Booking website or room type page. The route's `can:` middleware authorizes this request.
 */
class SetAmenityRequest extends FormRequest
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
            'amenity_id' => ['required', 'integer'],
            'active' => ['required', 'boolean'],
        ];
    }
}

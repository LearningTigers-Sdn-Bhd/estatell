<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One online booking switch on the Rates tab. The route's `can:` middleware authorizes this request.
 */
class SetRatePlanBookableRequest extends FormRequest
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
            'rate_plan_id' => ['required', 'integer'],
            'bookable' => ['required', 'boolean'],
        ];
    }
}

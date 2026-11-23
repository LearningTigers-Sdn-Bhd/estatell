<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A host checks guests in for a meal. The route's `can:` middleware checks `meals.redeem`.
 */
class RedeemMealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reservation_unit_id' => ['required', 'integer'],
            'meal_period_id' => ['required', 'integer'],
            'service_date' => ['required', 'date_format:Y-m-d'],
            'guests' => ['required', 'integer', 'min:1', 'max:99'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}

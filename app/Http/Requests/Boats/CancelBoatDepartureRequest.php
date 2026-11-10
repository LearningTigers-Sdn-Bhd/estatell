<?php

namespace App\Http\Requests\Boats;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel a departure on one date, or undo it, on the Boats board. The route checks `boats` and `reservations.manage`.
 */
class CancelBoatDepartureRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'cancelled' => ['required', 'boolean'],
        ];
    }

    public function sailingDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', (string) $this->validated('date'))->startOfDay();
    }
}

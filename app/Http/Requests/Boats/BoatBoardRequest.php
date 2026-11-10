<?php

namespace App\Http\Requests\Boats;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query of the Boats board: `date` (default the business date) and `direction` (default both). The route checks `boats`
 * and `reservations.view`.
 */
class BoatBoardRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m-d'],
            'direction' => ['nullable', Rule::in(['in', 'out', 'both'])],
        ];
    }

    public function direction(): string
    {
        return $this->input('direction') ?: 'both';
    }
}

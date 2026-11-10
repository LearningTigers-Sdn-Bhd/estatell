<?php

namespace App\Http\Requests\Boats;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query of the Boats board: `date` (default the business date) and `direction`, the open tab: `both` (All, the
 * default), `in`, `out` or `other` (Charter and Own boat). The route checks `boats`
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
            'direction' => ['nullable', Rule::in(['in', 'out', 'both', 'other'])],
        ];
    }

    /** @return 'in'|'out'|'both'|'other' */
    public function direction(): string
    {
        return match ($this->input('direction')) {
            'in' => 'in',
            'out' => 'out',
            'other' => 'other',
            default => 'both',
        };
    }
}

<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Taxes paid elsewhere form in More: the units, then the same fields as the one-unit form.
 */
class WaiveGroupTaxRequest extends WaiveUnitTaxRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unit_ids' => ['required', 'array', 'min:1', 'max:200'],
            'unit_ids.*' => ['required', 'integer'],
            ...parent::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_ids.required' => __('Choose at least one room.'),
            ...parent::messages(),
        ];
    }

    /**
     * @return list<int>
     */
    public function unitIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('unit_ids', [])));
    }
}

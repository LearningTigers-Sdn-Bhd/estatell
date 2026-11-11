<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The group add-on Dialog and its preview: the add-on fields of one unit plus the picked unit ids.
 */
class AddGroupAddOnRequest extends AddUnitAddOnRequest
{
    /**
     * Only the shape is checked here. `AddGroupAddOn` ignores ids of other reservations.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'unit_ids' => ['required', 'array', 'min:1', 'max:200'],
            'unit_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'unit_ids.required' => __('Pick at least one unit.'),
            'unit_ids.min' => __('Pick at least one unit.'),
        ];
    }

    /**
     * @return list<int>
     */
    public function unitIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('unit_ids')));
    }
}

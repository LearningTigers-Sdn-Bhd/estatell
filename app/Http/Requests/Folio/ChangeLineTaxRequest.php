<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Remove tax and Add tax Dialogs: one or more ids. Remove tax sends the ids of the tax lines on the charge, Add
 * tax sends the ids of the taxes to add.
 */
class ChangeLineTaxRequest extends LineManualActionRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'tax_ids' => ['required', 'array', 'min:1', 'max:20'],
            'tax_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'tax_ids.required' => __('Choose at least one tax.'),
            'tax_ids.min' => __('Choose at least one tax.'),
        ];
    }

    /**
     * @return list<int>
     */
    public function taxIds(): array
    {
        return array_values(array_map(intval(...), $this->validated('tax_ids')));
    }
}

<?php

namespace App\Http\Requests\Catalogues;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderChargeItemsRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
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
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function orderedIds(): array
    {
        return array_map('intval', array_values($this->validated('order')));
    }
}

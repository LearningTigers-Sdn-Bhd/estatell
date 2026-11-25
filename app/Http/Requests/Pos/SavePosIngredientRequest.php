<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An ingredient and its units. The Action checks what the data may do; the route's `can:` middleware checks the permission.
 */
class SavePosIngredientRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:64'],
            'base_unit' => ['required', 'string', 'max:20'],
            'purchase_unit' => ['nullable', 'string', 'max:20'],
            'purchase_to_base_ratio' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}

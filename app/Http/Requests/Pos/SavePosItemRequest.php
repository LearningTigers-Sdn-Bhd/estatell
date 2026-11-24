<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class SavePosItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:140'],
            'pos_category_id' => ['required', 'integer'],
            'transaction_code_id' => ['nullable', 'integer'],
            'meal_period_id' => ['nullable', 'integer'],
            'internal_name' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'price_includes_tax' => ['required', 'boolean'],
            'service_charge_exempt' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['required', 'boolean'],
            'variants' => ['required', 'array', 'min:1', 'max:20'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:80'],
            'variants.*.price' => ['required', 'decimal:0,2', 'min:0'],
            'variants.*.sku' => ['nullable', 'string', 'max:60'],
            'variants.*.active' => ['required', 'boolean'],
        ];
    }
}

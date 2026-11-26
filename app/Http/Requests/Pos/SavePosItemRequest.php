<?php

namespace App\Http\Requests\Pos;

use App\Enums\PosPricingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Only a new item sends its photo with the form. A saved item uploads it on its own.
            'image' => ['nullable', ...UploadPosItemImageRequest::IMAGE_RULES],
            'pos_category_id' => ['required', 'integer'],
            'transaction_code_id' => ['nullable', 'integer'],
            'meal_period_id' => ['nullable', 'integer'],
            'internal_name' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'price_includes_tax' => ['required', 'boolean'],
            'service_charge_exempt' => ['required', 'boolean'],
            'sold_by_weight' => ['nullable', 'boolean'],
            'weight_unit' => ['nullable', 'string', 'max:20'],
            'weight_unit_divisor' => ['nullable', 'decimal:0,3', 'min:0'],
            'shared_stock_count' => ['nullable', 'integer', 'min:0'],
            'daily_shared_stock_reset' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['required', 'boolean'],
            'variants' => ['required', 'array', 'min:1', 'max:20'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:80'],
            'variants.*.pricing_type' => ['nullable', Rule::enum(PosPricingType::class)],
            'variants.*.price' => ['required', 'decimal:0,2', 'min:0'],
            'variants.*.cost' => ['nullable', 'decimal:0,2', 'min:0'],
            'variants.*.sku' => ['nullable', 'string', 'max:60'],
            'variants.*.barcode' => ['nullable', 'string', 'max:60'],
            'variants.*.active' => ['required', 'boolean'],
        ];
    }
}

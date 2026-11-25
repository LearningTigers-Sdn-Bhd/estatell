<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class AddPosOrderLineRequest extends FormRequest
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
            'variant_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'modifier_option_ids' => ['nullable', 'array', 'max:40'],
            'modifier_option_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:255'],
            'course_number' => ['nullable', 'integer', 'between:1,9'],
            'seat_number' => ['nullable', 'integer', 'between:1,99'],
            'unit_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'cover_with_entitlement' => ['nullable', 'boolean'],
        ];
    }
}

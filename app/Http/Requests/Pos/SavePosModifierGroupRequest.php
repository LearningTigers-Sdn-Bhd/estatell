<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class SavePosModifierGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'min_select' => ['required', 'integer', 'min:0'],
            'max_select' => ['nullable', 'integer', 'min:1'],
            'max_per_option' => ['required', 'integer', 'min:1'],
            'free_included_count' => ['required', 'integer', 'min:0'],
            'active' => ['required', 'boolean'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
            'options' => ['required', 'array', 'min:1', 'max:40'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name' => ['required', 'string', 'max:100'],
            'options.*.price_delta' => ['required', 'decimal:0,2'],
            'options.*.active' => ['required', 'boolean'],
        ];
    }
}

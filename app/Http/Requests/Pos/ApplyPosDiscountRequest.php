<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class ApplyPosDiscountRequest extends FormRequest
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
            'type' => ['nullable', 'string', 'max:20'],
            'value' => ['nullable', 'decimal:0,2', 'min:0'],
            'line_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:160'],
            'remove' => ['nullable', 'boolean'],
            'exclude_addons' => ['nullable', 'boolean'],
            'approver_id' => ['nullable', 'integer'],
            'approver_pin' => ['nullable', 'string', 'max:12'],
        ];
    }
}

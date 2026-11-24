<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class RefundPosOrderRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.return_stock' => ['nullable', 'boolean'],
            'lines.*.restore_meal_entitlement' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:255'],
            'client_uuid' => ['nullable', 'uuid'],
            'approver_id' => ['nullable', 'integer'],
            'approver_pin' => ['nullable', 'string', 'max:12'],
        ];
    }
}

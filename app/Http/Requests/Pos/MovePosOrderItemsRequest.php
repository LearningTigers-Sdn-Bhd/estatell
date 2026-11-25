<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class MovePosOrderItemsRequest extends FormRequest
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
            'target_order_id' => ['required', 'integer'],
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer'],
        ];
    }
}

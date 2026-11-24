<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class StorePosOrderRequest extends FormRequest
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
            'register_id' => ['required', 'integer'],
            'pos_dining_option_id' => ['required', 'integer'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:99'],
            'reservation_unit_id' => ['nullable', 'integer'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }
}

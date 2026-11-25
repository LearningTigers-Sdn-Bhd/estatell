<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class SavePosComboSlotsRequest extends FormRequest
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
            'slots' => ['present', 'array', 'max:12'],
            'slots.*.name' => ['required', 'string', 'max:80'],
            'slots.*.min' => ['required', 'integer', 'min:0', 'max:20'],
            'slots.*.max' => ['required', 'integer', 'min:1', 'max:20'],
            'slots.*.options' => ['required', 'array', 'min:1', 'max:60'],
            'slots.*.options.*.variant_id' => ['required', 'integer'],
            'slots.*.options.*.upcharge' => ['required', 'decimal:0,2', 'min:0'],
        ];
    }
}

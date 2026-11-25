<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class SavePosTableRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:20'],
            'label' => ['nullable', 'string', 'max:40'],
            'seats' => ['required', 'integer', 'min:1', 'max:99'],
            'shape' => ['required', 'in:square,round,rect'],
            'x' => ['required', 'integer', 'min:0', 'max:4000'],
            'y' => ['required', 'integer', 'min:0', 'max:4000'],
            'width' => ['required', 'integer', 'min:40', 'max:800'],
            'height' => ['required', 'integer', 'min:40', 'max:800'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}

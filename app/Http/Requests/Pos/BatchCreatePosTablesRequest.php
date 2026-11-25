<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class BatchCreatePosTablesRequest extends FormRequest
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
            'prefix' => ['nullable', 'string', 'max:12'],
            'start' => ['required', 'integer', 'min:0', 'max:999'],
            'end' => ['required', 'integer', 'min:0', 'max:999'],
            'seats' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}

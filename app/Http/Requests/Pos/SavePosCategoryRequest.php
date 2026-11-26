<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class SavePosCategoryRequest extends FormRequest
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
            'transaction_code_id' => ['required', 'integer'],
            'internal_name' => ['nullable', 'string', 'max:60'],
            'color_token' => ['required', 'string', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'transaction_code_id' => __('account code'),
        ];
    }
}

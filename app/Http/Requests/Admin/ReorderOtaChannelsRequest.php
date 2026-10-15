<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The `can:admin` route middleware authorizes this request.
 */
class ReorderOtaChannelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function orderedIds(): array
    {
        return array_map('intval', array_values($this->validated('order')));
    }
}

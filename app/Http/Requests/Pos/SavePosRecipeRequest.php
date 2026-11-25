<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A recipe: ingredient id to quantity in its base unit. The Action checks what the data may do; the route's `can:` middleware checks the permission.
 */
class SavePosRecipeRequest extends FormRequest
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
            'quantities' => ['present', 'array'],
            'quantities.*' => ['numeric', 'gt:0', 'max:100000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff add or change an outlet. The route's `can:` middleware checks `pos.manage`.
 */
class SavePosOutletRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:30'],
            'default_charge_item_id' => ['required', 'integer'],
            'active' => ['required', 'boolean'],
        ];
    }
}

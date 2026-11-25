<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A transfer between two outlets of the property. The Action checks what the data may do; the route's `can:` middleware checks the permission.
 */
class StockTransferRequest extends FormRequest
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
            'ingredient_id' => ['required', 'integer'],
            'from_outlet_id' => ['required', 'integer'],
            'to_outlet_id' => ['required', 'integer', 'different:from_outlet_id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}

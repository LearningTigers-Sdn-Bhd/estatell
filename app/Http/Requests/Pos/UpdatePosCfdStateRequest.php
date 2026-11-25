<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The cashier terminal tells the customer-facing display which order is on screen and whether the checkout sheet is open.
 */
class UpdatePosCfdStateRequest extends FormRequest
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
            'order_id' => ['nullable', 'integer'],
            'checkout_active' => ['sometimes', 'boolean'],
        ];
    }
}

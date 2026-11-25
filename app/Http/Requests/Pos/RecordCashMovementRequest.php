<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class RecordCashMovementRequest extends FormRequest
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
            'kind' => ['required', 'string', 'in:float_in,safe_drop,payout,no_sale_open'],
            'amount' => ['nullable', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:160'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'counter_transaction_code_id' => ['nullable', 'integer'],
        ];
    }
}

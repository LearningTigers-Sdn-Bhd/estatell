<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class SettlePosOrderRequest extends FormRequest
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
            'tenders' => ['required', 'array', 'min:1', 'max:8'],
            'tenders.*.tender_type' => ['required', 'string', 'in:cash,card,e_wallet,duitnow_qr,payment_voucher,room_charge'],
            'tenders.*.amount' => ['required', 'decimal:0,2', 'min:0'],
            'tenders.*.payment_method_id' => ['nullable', 'integer'],
            'tenders.*.tendered_amount' => ['nullable', 'decimal:0,2'],
            'tenders.*.reservation_unit_id' => ['nullable', 'integer'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
            'guest_signed' => ['nullable', 'boolean'],
            'meal_confirmed' => ['nullable', 'boolean'],
            'approver_id' => ['nullable', 'integer'],
            'approver_pin' => ['nullable', 'string', 'max:12'],
        ];
    }
}

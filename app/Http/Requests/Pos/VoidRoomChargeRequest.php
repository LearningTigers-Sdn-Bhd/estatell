<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `POST /api/pos/v1/room-charges/{external_receipt_id}/void`. An `amount` below the original charge is a
 * partial refund, which front desk handles by hand.
 */
class VoidRoomChargeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outlet_code' => ['required', 'string', 'max:30'],
            'void_receipt_id' => ['required', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
            'amount' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'raw_payload' => ['nullable', 'array'],
        ];
    }
}

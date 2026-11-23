<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `POST /api/pos/v1/room-charges`. The amount is the gross room-charge tender, positive with at most two
 * decimals. A missing `reservation_unit_id` is allowed: the receipt is kept for front desk review.
 */
class PostRoomChargeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outlet_code' => ['required', 'string', 'max:30'],
            'external_receipt_id' => ['required', 'string', 'max:100'],
            'external_receipt_number' => ['required', 'string', 'max:100'],
            'reservation_unit_id' => ['nullable', 'integer'],
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'service_date' => ['nullable', 'date_format:Y-m-d'],
            'raw_payload' => ['nullable', 'array'],
        ];
    }
}

<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The offline sales of one register. Prices are not sent: the server prices every line from the snapshot it names.
 */
class SyncOfflinePosOrdersRequest extends FormRequest
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
            'outlet_id' => ['required', 'integer'],
            'orders' => ['required', 'array', 'min:1', 'max:100'],
            'orders.*.client_uuid' => ['required', 'uuid'],
            'orders.*.snapshot_version' => ['required', 'string', 'max:32'],
            'orders.*.shift_id' => ['nullable', 'integer'],
            'orders.*.lines' => ['required', 'array', 'min:1', 'max:50'],
            'orders.*.lines.*.variant_id' => ['required', 'integer'],
            'orders.*.lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'orders.*.tenders' => ['required', 'array', 'min:1', 'max:4'],
            'orders.*.tenders.*.tender_type' => ['required', 'string', Rule::in(['cash', 'duitnow_qr'])],
            'orders.*.tenders.*.amount' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'orders.*.tenders.*.tendered_amount' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'approve' => ['sometimes', 'boolean'],
            'approver_id' => ['nullable', 'integer'],
            'approver_pin' => ['nullable', 'string', 'max:12'],
        ];
    }
}

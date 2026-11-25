<?php

namespace App\Http\Requests\Pos;

use App\Enums\PosOperationMode;
use App\Enums\PosRoundingMode;
use App\Enums\PosRoundingPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class SavePosNativeOutletRequest extends FormRequest
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
            'service_charge_transaction_code_id' => ['nullable', 'integer'],
            'packaging_transaction_code_id' => ['nullable', 'integer'],
            'rounding_transaction_code_id' => ['nullable', 'integer'],
            'cash_variance_transaction_code_id' => ['nullable', 'integer'],
            'safe_clearing_transaction_code_id' => ['nullable', 'integer'],
            'sst_registration_number' => ['nullable', 'string', 'max:40'],
            'operation_mode' => ['required', Rule::enum(PosOperationMode::class)],
            'rounding_mode' => ['required', Rule::enum(PosRoundingMode::class)],
            'rounding_policy' => ['required', Rule::enum(PosRoundingPolicy::class)],
            'room_charge_limit' => ['nullable', 'decimal:0,2', 'min:0'],
            'require_room_charge_signature' => ['required', 'boolean'],
            'require_meal_redemption_confirmation' => ['required', 'boolean'],
            'require_reprint_pin' => ['required', 'boolean'],
            'require_dual_control' => ['required', 'boolean'],
            'print_receipt_after_payment' => ['required', 'boolean'],
            'print_drawer_slip' => ['required', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Reservations;

use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Check out form. A deposit refund from a user without `payment.refund` is dropped.
 */
class CheckOutUnitRequest extends FormRequest
{
    /**
     * The `can:front-desk.check-out` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'refund_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'required_with:refund_amount', 'uuid'],
        ];
    }

    public function refundAmount(): ?string
    {
        return filled($this->input('refund_amount')) && $this->user()->can(PermissionEnum::PaymentRefund->value)
            ? $this->string('refund_amount')->toString()
            : null;
    }

    public function reference(): ?string
    {
        return filled($this->input('reference')) ? $this->string('reference')->toString() : null;
    }
}

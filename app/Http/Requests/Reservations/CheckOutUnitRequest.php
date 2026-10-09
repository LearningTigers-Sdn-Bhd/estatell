<?php

namespace App\Http\Requests\Reservations;

use App\Data\Reservations\CheckOutCollectData;
use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Check out form, and the early check-out form, which adds the fee choices. Collect balance sends a method, a
 * reference and the amount the form showed. A deposit refund from a user without `payment.refund` is dropped, so is
 * Collect balance from a user without `payment.record`, and so is Do not charge from a user without
 * `front-desk.waive-fees`.
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
            'collect_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'payment_method_id' => ['nullable', 'required_with:collect_amount', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'required_with:refund_amount,collect_amount', 'uuid'],
            'do_not_charge' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expected_fee' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    public function refundAmount(): ?string
    {
        return filled($this->input('refund_amount')) && $this->user()->can(PermissionEnum::PaymentRefund->value)
            ? $this->string('refund_amount')->toString()
            : null;
    }

    public function collectBalance(): ?CheckOutCollectData
    {
        if (! filled($this->input('collect_amount')) || ! $this->user()->can(PermissionEnum::PaymentRecord->value)) {
            return null;
        }

        return new CheckOutCollectData(
            paymentMethodId: $this->integer('payment_method_id'),
            amount: $this->string('collect_amount')->toString(),
            reference: filled($this->input('reference')) ? $this->string('reference')->toString() : null,
            idempotencyKey: $this->string('idempotency_key')->toString(),
        );
    }

    public function doNotCharge(): bool
    {
        return $this->boolean('do_not_charge') && $this->user()->can(PermissionEnum::FrontDeskWaiveFees->value);
    }

    public function reason(): ?string
    {
        return filled($this->input('reason')) ? $this->string('reason')->toString() : null;
    }

    public function expectedFee(): ?string
    {
        return filled($this->input('expected_fee')) ? $this->string('expected_fee')->toString() : null;
    }
}

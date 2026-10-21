<?php

namespace App\Http\Requests\Reservations;

use App\Data\Reservations\CheckOutCollectData;
use App\Data\Reservations\CheckOutDamageData;
use App\Enums\PermissionEnum;
use App\Services\Platform\Impersonation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The Check out form, and the early check-out form, which adds the fee choices. Collect balance sends a method, a
 * reference and the amount the form showed. A deposit refund from a user without `payment.refund` is dropped, so is
 * Collect balance from a user without `payment.record`, and so is Do not charge from a user without
 * `front-desk.waive-fees`. A damage charge (`damage_amount`, above 0, and an optional `damage_note`) needs
 * `folio.post-charge`, else it is an error on `damage_amount`. During a support session a refund needs the owner's money switch, and is refused with an error
 * on `refund_amount` when it is off.
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
            'idempotency_key' => ['nullable', 'required_with:refund_amount,collect_amount,damage_amount', 'uuid'],
            'damage_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/', 'gt:0'],
            'damage_note' => ['nullable', 'string', 'max:100'],
            'do_not_charge' => ['boolean'],
            'left_yesterday' => ['boolean'],
            'stayed_last_night' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expected_fee' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->boolean('stayed_last_night') && ! $this->user()->can(PermissionEnum::ReservationsManage->value)) {
                $validator->errors()->add('stayed_last_night', __('You need permission to change reservations to add the night.'));
            }

            if (filled($this->input('damage_amount')) && ! $this->user()->can(PermissionEnum::FolioPostCharge->value)) {
                $validator->errors()->add('damage_amount', __('You need permission to post charges to keep a deposit for damages.'));
            }

            if (filled($this->input('refund_amount')) && ! app(Impersonation::class)->canCorrectMoney()) {
                $validator->errors()->add('refund_amount', __('The owner has not allowed refunds and voids during support access'));
            }
        }];
    }

    public function refundAmount(): ?string
    {
        return filled($this->input('refund_amount')) && app(Impersonation::class)->mayRefund($this->user())
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

    public function damage(): ?CheckOutDamageData
    {
        if (! filled($this->input('damage_amount'))) {
            return null;
        }

        return new CheckOutDamageData(
            amount: $this->string('damage_amount')->toString(),
            note: filled($this->input('damage_note')) ? $this->string('damage_note')->trim()->toString() : null,
        );
    }

    public function doNotCharge(): bool
    {
        return $this->boolean('do_not_charge') && $this->user()->can(PermissionEnum::FrontDeskWaiveFees->value);
    }

    public function stayedLastNight(): bool
    {
        return $this->boolean('stayed_last_night');
    }

    public function leftYesterday(): bool
    {
        return $this->boolean('left_yesterday');
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

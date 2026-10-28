<?php

namespace App\Http\Requests\Reservations;

use App\Data\Reservations\WalkInCollectData;
use App\Enums\PermissionEnum;
use App\Enums\StayType;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The walk-in form: the booking rules of `StoreBookingRequest`, with the source fixed to the Walk-in type, plus
 * `without_card` and the Payment section. The payment fields (`collect_room_charges`, `collect_deposit`,
 * `payment_method_id`, `reference`, `expected_total`) are read only for a user with `payment.record`, and
 * `without_card` only for a user with `front-desk.check-in-without-card`; for anyone else they are ignored. The form
 * sends choices and the total it showed, never a part amount. A blank total is not a validation error: it reads as a
 * total that changed, after the booking was tried, so a refused booking still shows its own errors. A day use walk-in
 * can be saved as already checked out (`status` = `checked_out` and `end_time`, for a user with `front-desk.check-out`):
 * the guest left today at `end_time`, which is after the start and not after now, and the payment is the room charges
 * and tax in full, whatever the boxes say. The
 * `can:reservations.manage` and `can:front-desk.check-in` route middleware authorize it.
 */
class StoreWalkInRequest extends StoreBookingRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'without_card' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['in_house', 'checked_out'])],
            'end_time' => ['required_if:status,checked_out', 'nullable', 'date_format:H:i'],
            ...($this->canRecordPayment() ? [
                'collect_room_charges' => ['nullable', 'boolean'],
                'collect_deposit' => ['nullable', 'boolean'],
                'payment_method_id' => [Rule::requiredIf(fn (): bool => $this->paymentAsked()), 'nullable', 'integer'],
                'reference' => ['nullable', 'string', 'max:100'],
                'expected_total' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            ] : []),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                if ($this->bookingSource() !== null && ! $this->bookingSource()->type->isWalkIn()) {
                    $validator->errors()->add('booking_source_id', __('A walk-in uses the Walk-in booking source.'));
                }

                if ($this->checkedOut()) {
                    $this->checkCheckedOut($validator);
                }

                if ($validator->errors()->isEmpty() && $this->paymentAsked() && $this->methodNeedsReference() && blank($this->input('reference'))) {
                    $validator->errors()->add('reference', __('This payment method needs a reference.'));
                }
            },
        ];
    }

    /**
     * The payment the form asks for, or null when the user may not record payments or no box is ticked.
     */
    public function paymentChoice(): ?WalkInCollectData
    {
        if (! $this->paymentAsked()) {
            return null;
        }

        return new WalkInCollectData(
            roomCharges: $this->checkedOut() || $this->boolean('collect_room_charges'),
            deposit: ! $this->checkedOut() && $this->boolean('collect_deposit'),
            paymentMethodId: $this->integer('payment_method_id'),
            reference: filled($this->input('reference')) ? trim($this->string('reference')->toString()) : null,
            expectedTotal: $this->string('expected_total')->toString(),
        );
    }

    /**
     * The moment the guest left, on the walk-in's date, or null for a walk-in that stays in house.
     */
    public function checkedOutAt(): ?CarbonImmutable
    {
        if (! $this->checkedOut()) {
            return null;
        }

        return CarbonImmutable::parse($this->string('arrival_date').' '.$this->string('end_time'), $this->timeZone());
    }

    public function checkedOut(): bool
    {
        return $this->input('status') === 'checked_out';
    }

    public function withoutCard(): bool
    {
        return $this->boolean('without_card') && $this->user()->can(PermissionEnum::FrontDeskCheckInWithoutCard->value);
    }

    private function checkCheckedOut(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $start = CarbonImmutable::parse($this->string('arrival_date').' '.$this->string('start_time'), $this->timeZone());
        $end = $this->checkedOutAt();

        if ($this->input('stay_type') !== StayType::DayUse->value) {
            $validator->errors()->add('status', __('Only a day use can be saved as already checked out.'));
        } elseif (! $this->user()->can(PermissionEnum::FrontDeskCheckOut->value)) {
            $validator->errors()->add('status', __('You cannot check a guest out.'));
        } elseif ($end === null || ! $end->isAfter($start)) {
            $validator->errors()->add('end_time', __('The end must be after the start.'));
        } elseif ($end->isAfter(CarbonImmutable::now($this->timeZone()))) {
            $validator->errors()->add('end_time', __('The end cannot be after now.'));
        }
    }

    private function timeZone(): string
    {
        return $this->property()->settings->time_zone ?? config('app.timezone');
    }

    private function canRecordPayment(): bool
    {
        return $this->user()->can(PermissionEnum::PaymentRecord->value);
    }

    private function paymentAsked(): bool
    {
        return $this->canRecordPayment() && ($this->checkedOut() || $this->boolean('collect_room_charges') || $this->boolean('collect_deposit'));
    }

    private function methodNeedsReference(): bool
    {
        return PaymentMethod::query()
            ->where('property_id', $this->property()->id)
            ->whereKey($this->integer('payment_method_id'))
            ->where('requires_reference', true)
            ->exists();
    }
}

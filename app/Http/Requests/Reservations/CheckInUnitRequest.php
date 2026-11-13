<?php

namespace App\Http\Requests\Reservations;

use App\Data\Reservations\CollectNowData;
use App\Enums\EndedStayChoice;
use App\Enums\PermissionEnum;
use App\Services\Guests\CountryOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Check in form. It sends choices only: a nationality, the overrides, and the Collect now choices (`room_charges` takes the unit's full bill as one Advance in place of the taxes). Amounts are
 * never sent, the Action works them out. An override or Collect now from a user without its permission is dropped. `ended_stay_choice` is sent only for a stay that was not logged in time.
 */
class CheckInUnitRequest extends FormRequest
{
    /**
     * The `can:front-desk.check-in` route middleware authorizes this request. Moving the stay to today also changes its
     * dates, so it needs the permission of Change dates.
     */
    public function authorize(): bool
    {
        return $this->endedStayChoice() !== EndedStayChoice::ArrivingToday
            || $this->user()->can(PermissionEnum::ReservationsManage->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ended_stay_choice' => ['nullable', Rule::enum(EndedStayChoice::class)],
            'arrived_at' => ['nullable', 'required_if:ended_stay_choice,'.EndedStayChoice::ArrivedLastNight->value, 'date_format:H:i'],
            'nationality_code' => ['nullable', Rule::in(app(CountryOptions::class)->codes())],
            'without_card' => ['boolean'],
            'credit_override_reason' => ['nullable', 'string', 'max:500'],
            'collect_now' => ['boolean'],
            'collect_taxes' => ['boolean'],
            'collect_tax_ids' => ['nullable', 'array'],
            'collect_tax_ids.*' => ['integer'],
            'collect_charge_ids' => ['nullable', 'array'],
            'collect_charge_ids.*' => ['integer'],
            'collect_master_tax_ids' => ['nullable', 'array'],
            'collect_master_tax_ids.*' => ['integer'],
            'room_charges' => ['boolean'],
            'collect_deposit' => ['boolean'],
            'payment_method_id' => ['nullable', 'required_if:collect_now,true', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'required_if:collect_now,true', 'uuid'],
        ];
    }

    public function endedStayChoice(): ?EndedStayChoice
    {
        return EndedStayChoice::tryFrom((string) $this->input('ended_stay_choice'));
    }

    public function arrivedAt(): ?string
    {
        return filled($this->input('arrived_at')) ? $this->string('arrived_at')->toString() : null;
    }

    public function nationality(): ?string
    {
        return filled($this->input('nationality_code')) ? $this->string('nationality_code')->toString() : null;
    }

    public function withoutCard(): bool
    {
        return $this->boolean('without_card') && $this->user()->can(PermissionEnum::FrontDeskCheckInWithoutCard->value);
    }

    public function creditOverrideReason(): ?string
    {
        return filled($this->input('credit_override_reason')) && $this->user()->can(PermissionEnum::CompanyCreditOverride->value)
            ? $this->string('credit_override_reason')->toString()
            : null;
    }

    public function collectNow(): ?CollectNowData
    {
        if (! $this->boolean('collect_now') || ! $this->user()->can(PermissionEnum::PaymentRecord->value)) {
            return null;
        }

        return new CollectNowData(
            taxes: $this->boolean('collect_taxes'),
            deposit: $this->boolean('collect_deposit'),
            paymentMethodId: $this->integer('payment_method_id'),
            reference: filled($this->input('reference')) ? $this->string('reference')->toString() : null,
            idempotencyKey: $this->string('idempotency_key')->toString(),
            roomCharges: $this->boolean('room_charges'),
            taxIds: $this->has('collect_tax_ids') ? array_values(array_map(intval(...), (array) $this->input('collect_tax_ids', []))) : null,
            chargeCodeIds: array_values(array_map(intval(...), (array) $this->input('collect_charge_ids', []))),
            masterTaxIds: array_values(array_map(intval(...), (array) $this->input('collect_master_tax_ids', []))),
        );
    }
}

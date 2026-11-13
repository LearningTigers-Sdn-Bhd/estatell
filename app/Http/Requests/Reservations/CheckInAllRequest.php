<?php

namespace App\Http\Requests\Reservations;

use App\Data\Reservations\CollectNowData;
use App\Enums\EndedStayChoice;
use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckInAllRequest extends FormRequest
{
    /**
     * The `can:front-desk.check-in` route middleware authorizes this request. Moving stays to today also changes their
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
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer'],
            'without_card' => ['boolean'],
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
            'ended_stay_choice' => ['nullable', Rule::enum(EndedStayChoice::class)],
            'arrived_at' => ['nullable', 'required_if:ended_stay_choice,'.EndedStayChoice::ArrivedLastNight->value, 'date_format:H:i'],
        ];
    }

    /**
     * @return list<int>
     */
    public function unitIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('unit_ids')));
    }

    public function endedStayChoice(): ?EndedStayChoice
    {
        return EndedStayChoice::tryFrom((string) $this->input('ended_stay_choice'));
    }

    public function arrivedAt(): ?string
    {
        return filled($this->input('arrived_at')) ? $this->string('arrived_at')->toString() : null;
    }

    public function withoutCard(): bool
    {
        return $this->boolean('without_card') && $this->user()->can(PermissionEnum::FrontDeskCheckInWithoutCard->value);
    }

    /**
     * The Collect now choices every chosen unit shares. Null without `collect_now` or without `payment.record`.
     */
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

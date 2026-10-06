<?php

namespace App\Http\Requests\Reservations;

use App\Enums\PermissionEnum;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Change dates form and its preview. Dates and choices only, never an amount.
 */
class ChangeUnitDatesRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request.
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
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'do_not_charge' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expected_fee' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'restriction_override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function arrival(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('arrival_date')->toString())->startOfDay();
    }

    public function departure(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('departure_date')->toString())->startOfDay();
    }

    /**
     * Do not charge is for staff with `front-desk.waive-fees`. For anyone else the choice is dropped.
     */
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

    /**
     * Only a user with `rates.override-restrictions` can pass the reason.
     */
    public function restrictionOverrideReason(): ?string
    {
        return filled($this->input('restriction_override_reason')) && $this->user()->can(PermissionEnum::RatesOverrideRestrictions->value)
            ? $this->string('restriction_override_reason')->toString()
            : null;
    }
}

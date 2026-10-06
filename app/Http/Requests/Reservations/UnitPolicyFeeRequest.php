<?php

namespace App\Http\Requests\Reservations;

use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Cancel unit and Mark no-show forms. They send choices only: the amount is never sent, only the fee the form
 * showed, so the Action can refuse when it changed.
 */
class UnitPolicyFeeRequest extends FormRequest
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
            'do_not_charge' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expected_fee' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'remove_add_ons' => ['boolean'],
        ];
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
}

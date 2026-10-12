<?php

namespace App\Http\Requests\Reservations;

use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Cancel reservation form. It sends the ticked units and choices, plus the fee the form showed for each unit
 * (never an amount to post), so the Action can refuse when a fee changed.
 */
class CancelReservationRequest extends FormRequest
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
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer'],
            'expected_fees' => ['nullable', 'array'],
            'expected_fees.*' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'do_not_charge' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'remove_add_ons' => ['boolean'],
        ];
    }

    /**
     * @return list<int>
     */
    public function unitIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('unit_ids')));
    }

    /**
     * @return array<int, string|null>
     */
    public function expectedFees(): array
    {
        $fees = [];

        foreach ((array) $this->input('expected_fees', []) as $unitId => $fee) {
            $fees[(int) $unitId] = filled($fee) ? (string) $fee : null;
        }

        return $fees;
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
}

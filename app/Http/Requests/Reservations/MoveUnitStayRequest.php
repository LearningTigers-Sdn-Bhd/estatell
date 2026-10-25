<?php

namespace App\Http\Requests\Reservations;

use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Timeline Dialog for a Confirmed bar moved to new dates and a room or bed. `MoveUnit` needs the reason when the
 * room changes.
 */
class MoveUnitStayRequest extends MoveUnitStayPreviewRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['nullable', 'string', 'max:500'],
            'restriction_override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function reason(): ?string
    {
        return filled($this->input('reason')) ? $this->string('reason')->toString() : null;
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

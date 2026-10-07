<?php

namespace App\Http\Requests\NightAudit;

use App\Enums\PermissionEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunNightAuditRequest extends FormRequest
{
    /**
     * `force` is refused for a user without `night-audit.force`, and a forced run needs a reason.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean', Rule::prohibitedIf(fn (): bool => ! $this->user()->can(PermissionEnum::NightAuditForce->value))],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn (): bool => $this->boolean('force'))],
        ];
    }
}

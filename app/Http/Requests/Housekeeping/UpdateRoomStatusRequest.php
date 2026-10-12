<?php

namespace App\Http\Requests\Housekeeping;

use App\Enums\HousekeepingStatus;
use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomStatusRequest extends FormRequest
{
    /**
     * `can:housekeeping.update` on the route covers the board. Inspected also needs `housekeeping.inspect`, which
     * depends on the payload.
     */
    public function authorize(): bool
    {
        return $this->input('status') !== HousekeepingStatus::Inspected->value
            || $this->user()->can(PermissionEnum::HousekeepingInspect->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(HousekeepingStatus::class)],
        ];
    }

    public function status(): HousekeepingStatus
    {
        return HousekeepingStatus::from($this->validated('status'));
    }
}

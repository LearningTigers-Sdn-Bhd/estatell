<?php

namespace App\Http\Requests\Housekeeping;

use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetNeedsMaintenanceRequest extends FormRequest
{
    /**
     * `can:housekeeping.update` on the route lets staff raise the flag. Dismissing it needs `rooms.block`, which
     * depends on the payload.
     */
    public function authorize(): bool
    {
        return $this->boolean('needs_maintenance')
            || $this->user()->can(PermissionEnum::RoomsBlock->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'needs_maintenance' => ['required', 'boolean'],
        ];
    }
}

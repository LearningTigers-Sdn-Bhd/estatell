<?php

namespace App\Http\Requests\Housekeeping;

use App\Enums\PermissionEnum;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignHousekeeperRequest extends FormRequest
{
    /**
     * The `can:housekeeping.update` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The assignee is active staff of the property who can update the board.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                $assignee = $this->assignee();

                if ($assignee === null || ! $assignee->can(PermissionEnum::HousekeepingUpdate->value)) {
                    $fail(__('Pick a staff member who can update housekeeping.'));
                }
            }],
        ];
    }

    public function assignee(): ?User
    {
        $id = $this->input('assigned_to');

        if ($id === null || $id === '') {
            return null;
        }

        return User::query()
            ->where('property_id', $this->route('property')->id)
            ->whereNull('deactivated_at')
            ->find((int) $id);
    }
}

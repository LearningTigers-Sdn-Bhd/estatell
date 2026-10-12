<?php

namespace App\Http\Requests\Housekeeping;

use App\Enums\HousekeepingBulkAction;
use App\Enums\PermissionEnum;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateRoomsRequest extends FormRequest
{
    public const MAX_ROOMS = 200;

    /**
     * `can:housekeeping.update` on the route covers the board. Mark inspected also needs `housekeeping.inspect`, which
     * depends on the payload.
     */
    public function authorize(): bool
    {
        return $this->input('action') !== HousekeepingBulkAction::MarkInspected->value
            || $this->user()->can(PermissionEnum::HousekeepingInspect->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'room_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROOMS],
            'room_ids.*' => ['integer'],
            'action' => ['required', Rule::enum(HousekeepingBulkAction::class)],
            'assigned_to' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->input('action') !== HousekeepingBulkAction::Assign->value || $value === null) {
                    return;
                }

                $assignee = $this->assignee();

                if ($assignee === null || ! $assignee->can(PermissionEnum::HousekeepingUpdate->value)) {
                    $fail(__('Pick a staff member who can update housekeeping.'));
                }
            }],
            'remark' => [Rule::requiredIf($this->input('action') === HousekeepingBulkAction::AddRemark->value), 'nullable', 'string', 'max:500'],
        ];
    }

    public function action(): HousekeepingBulkAction
    {
        return HousekeepingBulkAction::from($this->validated('action'));
    }

    /**
     * @return list<int>
     */
    public function roomIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('room_ids'))));
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

    public function remark(): ?string
    {
        $remark = trim((string) $this->validated('remark'));

        return $remark === '' ? null : $remark;
    }
}

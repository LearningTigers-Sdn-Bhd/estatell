<?php

namespace App\Http\Requests\GuestRequests;

use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignGuestRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['user_id' => ['nullable', 'integer']];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->assignee() === null && $this->filled('user_id')) {
                $validator->errors()->add('user_id', __('That person cannot work on requests.'));
            }
        }];
    }

    /**
     * The person to assign, a member of the property who may resolve requests, or null to unassign.
     */
    public function assignee(): ?User
    {
        /** @var Property $property */
        $property = $this->route('property');
        $user = $this->filled('user_id') ? User::query()->where('property_id', $property->id)->find($this->integer('user_id')) : null;

        return $user?->can(PermissionEnum::GuestRequestsResolve->value) ? $user : null;
    }
}

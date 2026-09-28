<?php

namespace App\Http\Requests\Staff;

use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
{
    /**
     * The `can:roles.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Unticking every box sends no field at all, so a missing list means no permissions.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['permissions' => $this->input('permissions', [])]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::enum(PermissionEnum::class)],
        ];
    }

    /**
     * @return list<PermissionEnum>
     */
    public function permissions(): array
    {
        return array_map(fn (string $value): PermissionEnum => PermissionEnum::from($value), array_values($this->validated('permissions')));
    }
}

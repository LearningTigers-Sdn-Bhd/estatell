<?php

namespace App\Http\Requests\Staff;

use App\Enums\PermissionEnum;
use App\Models\Property;
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
     * `roles` maps each role id of this property to its ticked permission names.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'roles' => ['required', Rule::array($property->roles()->pluck('id')->all())],
            'roles.*' => ['present', 'array'],
            'roles.*.*' => ['string', Rule::enum(PermissionEnum::class)],
        ];
    }

    /**
     * @return array<int, list<PermissionEnum>>
     */
    public function permissionsByRoleId(): array
    {
        return collect($this->validated('roles'))
            ->map(fn (array $names): array => array_map(fn (string $name): PermissionEnum => PermissionEnum::from($name), array_values($names)))
            ->all();
    }
}

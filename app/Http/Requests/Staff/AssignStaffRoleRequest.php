<?php

namespace App\Http\Requests\Staff;

use App\Models\Property;
use App\Services\Staff\AssignableRoles;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignStaffRoleRequest extends FormRequest
{
    /**
     * The `can:staff.manage` route middleware authorizes this request.
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
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'role_id' => ['required', 'integer', Rule::in(app(AssignableRoles::class)->for($property, $this->user())->modelKeys())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['role_id.in' => __('Choose a role you are allowed to give.')];
    }
}

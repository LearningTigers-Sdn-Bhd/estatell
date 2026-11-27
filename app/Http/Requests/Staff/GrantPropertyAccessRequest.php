<?php

namespace App\Http\Requests\Staff;

use App\Data\Staff\GroupColleagueData;
use App\Models\Property;
use App\Services\Staff\AssignableRoles;
use App\Services\Staff\StaffProperties;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantPropertyAccessRequest extends FormRequest
{
    /**
     * The `can:staff.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The staff member must be one of the group colleagues offered (`StaffProperties::colleaguesFor()`), and the role
     * one the actor may give here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'user_id' => ['required', 'integer', Rule::in(array_map(fn (GroupColleagueData $colleague): int => $colleague->id, app(StaffProperties::class)->colleaguesFor($property)))],
            'role_id' => ['required', 'integer', Rule::in(app(AssignableRoles::class)->for($property, $this->user())->modelKeys())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.in' => __('Choose a staff member of another property in this group.'),
            'role_id.in' => __('Choose a role you are allowed to give.'),
        ];
    }
}

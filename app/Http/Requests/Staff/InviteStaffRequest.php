<?php

namespace App\Http\Requests\Staff;

use App\Models\Property;
use App\Services\Staff\AssignableRoles;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InviteStaffRequest extends FormRequest
{
    /**
     * The `can:staff.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email'),
                Rule::unique('staff_invitations', 'email')
                    ->where('property_id', $property->id)
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at'),
            ],
            'role_id' => [
                'required', 'integer',
                Rule::in(app(AssignableRoles::class)->for($property, $this->user())->modelKeys()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('This email already has an account or an open invitation.'),
            'role_id.in' => __('Choose a role you are allowed to give.'),
        ];
    }
}

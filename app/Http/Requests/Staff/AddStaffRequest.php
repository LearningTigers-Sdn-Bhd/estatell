<?php

namespace App\Http\Requests\Staff;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Add one staff user with a temp password. Same email and role rules as an invitation, plus a name.
 */
class AddStaffRequest extends InviteStaffRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            ...parent::rules(),
        ];
    }
}

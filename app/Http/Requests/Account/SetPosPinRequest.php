<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A staff member sets their own POS PIN. Their current password proves it is them at the keyboard, not a cashier on a
 * left-open session. Length rules by role live in `SetPosStaffPin`.
 */
class SetPosPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'pin' => ['required', 'string', 'max:12'],
        ];
    }
}

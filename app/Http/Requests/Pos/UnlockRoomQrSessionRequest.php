<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The stay PIN and the primary guest's last name. Both are checked by the Action, never logged.
 */
class UnlockRoomQrSessionRequest extends FormRequest
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
            'pin' => ['required', 'digits:4'],
            'last_name' => ['required', 'string', 'max:80'],
        ];
    }
}

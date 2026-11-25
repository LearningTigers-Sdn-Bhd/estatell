<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pick a name and enter a PIN on a registered terminal. Property scoping and PIN checks happen in `SwitchPosActor`.
 */
class SwitchPosActorRequest extends FormRequest
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
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'max:12'],
        ];
    }
}

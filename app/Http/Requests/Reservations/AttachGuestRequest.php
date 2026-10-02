<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AttachGuestRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request.
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
        return [
            'guest_id' => ['required', 'integer'],
        ];
    }
}

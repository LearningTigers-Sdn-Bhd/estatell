<?php

namespace App\Http\Requests\Housekeeping;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetRoomRemarkRequest extends FormRequest
{
    /**
     * The `can:housekeeping.update` route middleware authorizes this request.
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
            'remark' => ['nullable', 'string', 'max:500'],
        ];
    }
}

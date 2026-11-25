<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A kitchen screen may only move a line to one of these three statuses. The Action checks the path from the current one.
 */
class UpdateKitchenLineStatusRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:preparing,ready,served'],
        ];
    }
}

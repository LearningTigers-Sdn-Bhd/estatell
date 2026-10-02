<?php

namespace App\Http\Requests\Reservations;

use App\Enums\BillingMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationGroupRequest extends FormRequest
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
            'group_name' => ['nullable', 'string', 'max:150'],
            'billing_mode' => ['required', Rule::enum(BillingMode::class)],
        ];
    }
}

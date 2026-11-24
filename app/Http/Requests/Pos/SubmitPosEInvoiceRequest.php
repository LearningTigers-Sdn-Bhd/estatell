<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class SubmitPosEInvoiceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:200'],
            'tin' => ['required', 'string', 'max:30'],
            'id_type' => ['required', 'string', 'in:NRIC,PASSPORT,BRN,ARMY'],
            'id_value' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'state_code' => ['nullable', 'string', 'max:3'],
            'country_code' => ['nullable', 'string', 'max:3'],
            'sst_registration' => ['nullable', 'string', 'max:40'],
        ];
    }
}

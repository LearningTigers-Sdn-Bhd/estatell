<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The buyer details a guest gives to get an e-invoice for their own receipt. The same fields staff enter at the counter.
 */
class ClaimPosReceiptRequest extends FormRequest
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
        ];
    }
}

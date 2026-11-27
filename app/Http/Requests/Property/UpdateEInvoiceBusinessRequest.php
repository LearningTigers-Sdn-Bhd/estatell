<?php

namespace App\Http\Requests\Property;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** E-invoicing › Business details: the hotel details printed on every e-invoice (the supplier). */
class UpdateEInvoiceBusinessRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
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
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier_tin' => ['nullable', 'string', 'max:30'],
            'supplier_brn' => ['nullable', 'string', 'max:40'],
            'supplier_sst_registration' => ['nullable', 'string', 'max:30'],
            'supplier_ttx_registration' => ['nullable', 'string', 'max:30'],
            'supplier_msic_code' => ['nullable', 'string', 'max:5'],
            'supplier_business_description' => ['nullable', 'string', 'max:255'],
            'supplier_email' => ['nullable', 'email', 'max:255'],
            'supplier_phone' => ['nullable', 'string', 'max:30'],
            'supplier_address_line1' => ['nullable', 'string', 'max:255'],
            'supplier_address_line2' => ['nullable', 'string', 'max:255'],
            'supplier_city' => ['nullable', 'string', 'max:100'],
            'supplier_postal_code' => ['nullable', 'string', 'max:20'],
            'supplier_state_code' => ['nullable', 'string', 'max:2'],
        ];
    }
}

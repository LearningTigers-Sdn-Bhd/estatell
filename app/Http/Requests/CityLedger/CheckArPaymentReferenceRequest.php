<?php

namespace App\Http\Requests\CityLedger;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckArPaymentReferenceRequest extends FormRequest
{
    /**
     * The `can:city-ledger.payment` route middleware authorizes this request.
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
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}

<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The new opening balance of a folio and the reason for the change. The amount is signed and may be typed as 1,080.00.
 * Zero removes the opening balance. The `can:folio.manual` route middleware authorizes this request.
 */
class ChangeOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('amount'))) {
            $this->merge(['amount' => str_replace([',', ' '], '', trim($this->input('amount')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => __('Enter an amount such as 1,080.00. A minus amount is a credit.'),
            'amount.regex' => __('Enter an amount such as 1,080.00. A minus amount is a credit.'),
            'reason.required' => __('Describe the reason.'),
        ];
    }
}

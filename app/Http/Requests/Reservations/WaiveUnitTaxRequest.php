<?php

namespace App\Http\Requests\Reservations;

use App\Enums\TaxWaiverReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Taxes paid elsewhere form: the taxes to stop charging, who collected them and the reference.
 */
class WaiveUnitTaxRequest extends FormRequest
{
    /**
     * The `can:folio.waive-tax` route middleware authorizes this request.
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
            'tax_ids' => ['required', 'array', 'min:1', 'max:20'],
            'tax_ids.*' => ['required', 'integer'],
            'reason' => ['required', Rule::enum(TaxWaiverReason::class)],
            'paid_to' => [Rule::requiredIf(fn (): bool => $this->reason() === TaxWaiverReason::PaidElsewhere), 'nullable', 'string', 'max:60'],
            'reference' => ['required', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tax_ids.required' => __('Choose at least one tax.'),
            'paid_to.required' => __('Enter who collected the tax.'),
            'reference.required' => __('Enter the booking number or the receipt number.'),
        ];
    }

    /**
     * @return list<int>
     */
    public function taxIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('tax_ids', [])));
    }

    public function reason(): ?TaxWaiverReason
    {
        return TaxWaiverReason::tryFrom((string) $this->input('reason'));
    }
}

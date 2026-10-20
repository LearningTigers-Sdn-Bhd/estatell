<?php

namespace App\Http\Requests\CityLedger;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddOldSystemInvoiceRequest extends FormRequest
{
    /**
     * The `can:folio.manual` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `AddOldSystemInvoice` checks the window, the company, the dates, the amount and the number.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:50'],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'due_on' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}

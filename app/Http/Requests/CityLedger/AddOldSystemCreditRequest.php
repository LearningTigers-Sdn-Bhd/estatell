<?php

namespace App\Http\Requests\CityLedger;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddOldSystemCreditRequest extends FormRequest
{
    /**
     * The `can:folio.manual` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `AddOldSystemCredit` checks the window, the company, the date and the amount.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}

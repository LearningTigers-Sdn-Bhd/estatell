<?php

namespace App\Http\Requests\CityLedger;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AllocateArPaymentRequest extends FormRequest
{
    /**
     * The `can:city-ledger.allocate` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `AllocateArPayment` checks the invoices and the amounts.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.ar_invoice_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * Amount by AR invoice id, in the order sent.
     *
     * @return array<int, string>
     */
    public function amounts(): array
    {
        $amounts = [];

        foreach ($this->validated('allocations') as $line) {
            $amounts[(int) $line['ar_invoice_id']] = (string) $line['amount'];
        }

        return $amounts;
    }
}

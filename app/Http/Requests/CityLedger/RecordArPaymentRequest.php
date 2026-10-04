<?php

namespace App\Http\Requests\CityLedger;

use App\Data\CityLedger\NewArPaymentData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RecordArPaymentRequest extends FormRequest
{
    /**
     * The `can:city-ledger.payment` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `RecordArPayment` checks the method, the amount, the reference and the date.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:20'],
            'received_on' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
            'allocate' => ['nullable', 'boolean'],
        ];
    }

    public function paymentData(): NewArPaymentData
    {
        return new NewArPaymentData(
            paymentMethodId: $this->integer('payment_method_id'),
            amount: $this->string('amount')->toString(),
            idempotencyKey: $this->string('idempotency_key')->toString(),
            receivedOn: $this->filled('received_on') ? CarbonImmutable::parse($this->string('received_on')->toString()) : null,
            reference: $this->input('reference'),
            notes: $this->input('notes'),
        );
    }

    public function shouldAllocate(): bool
    {
        return $this->boolean('allocate');
    }
}

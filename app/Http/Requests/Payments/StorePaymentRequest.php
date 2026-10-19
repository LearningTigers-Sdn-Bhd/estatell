<?php

namespace App\Http\Requests\Payments;

use App\Data\Payments\NewPaymentData;
use App\Enums\PaymentPurpose;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    /**
     * The `can:payment.record` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `RecordPayment` checks the method, the amount, the reference and the folio, and
     * refuses with a code.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'integer'],
            'purpose' => ['required', Rule::enum(PaymentPurpose::class)],
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'service_date' => ['nullable', 'string', 'max:10'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => __('Enter an amount such as 25.00.'),
            'payment_method_id.required' => __('Choose a payment method.'),
        ];
    }

    public function toPaymentData(): NewPaymentData
    {
        return new NewPaymentData(
            paymentMethodId: $this->integer('payment_method_id'),
            purpose: $this->enum('purpose', PaymentPurpose::class) ?? PaymentPurpose::Settlement,
            amount: $this->string('amount')->toString(),
            reference: $this->filled('reference') ? $this->string('reference')->toString() : null,
            idempotencyKey: $this->string('idempotency_key')->toString(),
            serviceDate: $this->filled('service_date') ? $this->string('service_date')->toString() : null,
        );
    }
}

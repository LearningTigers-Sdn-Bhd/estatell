<?php

namespace App\Http\Requests\Payments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRefundRequest extends FormRequest
{
    /**
     * The `can:payment.refund` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `RefundPayment` checks the payment, the folio and the amount left.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'payment_method_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
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
        ];
    }

    public function methodId(): ?int
    {
        return $this->filled('payment_method_id') ? $this->integer('payment_method_id') : null;
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->toString() : null;
    }

    public function reference(): ?string
    {
        return $this->filled('reference') ? $this->string('reference')->toString() : null;
    }
}

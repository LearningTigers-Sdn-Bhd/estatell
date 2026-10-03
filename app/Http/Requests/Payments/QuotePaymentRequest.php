<?php

namespace App\Http\Requests\Payments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class QuotePaymentRequest extends FormRequest
{
    /**
     * The `can:payment.record` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. An invalid amount answers with the quote's refusal message.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['nullable', 'string', 'max:20'],
        ];
    }
}

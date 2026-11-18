<?php

namespace App\Http\Requests\BookingEngine;

use App\Enums\PaymentMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Test payments switch on the General tab. The route's `can:` middleware authorizes this request.
 */
class SetPaymentModeRequest extends FormRequest
{
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
            'payment_mode' => ['required', Rule::enum(PaymentMode::class)],
        ];
    }

    public function paymentMode(): PaymentMode
    {
        return PaymentMode::from($this->string('payment_mode')->toString());
    }
}

<?php

namespace App\Http\Requests\Payments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVoidRequest extends FormRequest
{
    /**
     * The `can:payment.refund` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `VoidPayment` checks the payment and the day.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function notes(): ?string
    {
        return $this->filled('notes') ? $this->string('notes')->trim()->toString() : null;
    }
}

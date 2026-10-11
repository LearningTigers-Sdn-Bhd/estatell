<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of Print receipts on the group check-out success state: the ids of the payments the save recorded. The
 * `can:payment.record` route middleware authorizes it. A payment of another reservation or a refund is a 404 in the
 * controller.
 */
class CheckOutGroupReceiptsRequest extends FormRequest
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
            'payments' => ['required', 'array', 'min:1', 'max:300'],
            'payments.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function paymentIds(): array
    {
        return array_map('intval', $this->validated('payments'));
    }
}

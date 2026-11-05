<?php

namespace App\Http\Requests\Booking;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The refund the guest saw. The token middleware has already checked the client.
 * See docs/29-booking-engine/02-api.md#manage-booking.
 */
class CancelOnlineBookingRequest extends FormRequest
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
            'expected_refund' => ['required', 'decimal:0,2', 'min:0'],
        ];
    }

    public function expectedRefund(): string
    {
        return (string) $this->validated('expected_refund');
    }
}

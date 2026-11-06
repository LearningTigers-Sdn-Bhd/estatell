<?php

namespace App\Http\Requests\Booking;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The short code and email a guest types to find their booking. The token middleware has already checked the client.
 * See docs/29-booking-engine/02-api.md#find-a-booking.
 */
class FindOnlineBookingRequest extends FormRequest
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
            'short_code' => ['required', 'string', 'max:12'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * The guest's address from the microsite or the owner's server. Without a valid one, the limit counts the caller's own
     * address, so every guest of that caller shares it.
     */
    public function guestIp(): string
    {
        $guestIp = $this->header('X-Guest-IP');

        return is_string($guestIp) && filter_var($guestIp, FILTER_VALIDATE_IP) !== false ? $guestIp : (string) $this->ip();
    }
}

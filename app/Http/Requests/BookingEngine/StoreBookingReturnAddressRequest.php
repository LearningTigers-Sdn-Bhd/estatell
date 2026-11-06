<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new return address on the API tab: a full `https` address with no `#` part, because estatell adds the hold token and
 * payment status to its query. The route's `can:` middleware checks `booking-website.api`.
 */
class StoreBookingReturnAddressRequest extends FormRequest
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
            'url' => ['required', 'string', 'max:500', 'url:https', 'not_regex:/#/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.url' => __('Enter a full address that starts with https://.'),
            'url.not_regex' => __('Remove the part after #.'),
        ];
    }

    public function returnUrl(): string
    {
        return trim($this->string('url')->toString());
    }
}

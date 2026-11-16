<?php

namespace App\Http\Requests\Landing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The public Contact Us form. `website` is a honeypot: people never see it, bots fill it.
 */
class ContactEnquiryRequest extends FormRequest
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
            'hotel_name' => ['required', 'string', 'max:150'],
            'location' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s\-()]{5,}$/'],
            'room_count' => ['required', 'integer', 'min:1', 'max:9999'],
            'message' => ['required', 'string', 'max:2000'],
            'website' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter a valid mobile number, for example +6012 345 6789.'),
            'website.prohibited' => __('We could not send your enquiry. Please try again.'),
        ];
    }
}

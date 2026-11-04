<?php

namespace App\Http\Requests\Kiosk;

use App\Http\Responses\ApiError;
use App\Services\Kiosk\KioskBookingLookup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `{ reference }` or `{ qr_token }`. A body with neither is a booking that was not found: the contract has no other answer.
 */
class FindBookingRequest extends FormRequest
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
            'reference' => ['nullable', 'string', 'max:40'],
            'qr_token' => ['nullable', 'string', 'max:200'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(ApiError::response(KioskBookingLookup::BOOKING_NOT_FOUND, __('We could not find that booking.'), 404));
    }
}

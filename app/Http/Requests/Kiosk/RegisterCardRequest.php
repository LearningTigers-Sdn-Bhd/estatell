<?php

namespace App\Http\Requests\Kiosk;

use App\Http\Responses\ApiError;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A card UID as hex. A body with no usable UID is answered `card_unknown` (the card is handed back); the value is
 * not echoed back or logged.
 */
class RegisterCardRequest extends FormRequest
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
        return ['uid' => ['required', 'string', 'max:40']];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(ApiError::response('card_unknown', __('This card is not known. Please see the front desk.'), 404));
    }
}

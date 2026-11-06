<?php

namespace App\Http\Requests\Kiosk;

use App\Http\Resources\Kiosk\V1\KioskSignedResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The guest's signature. A body that is not valid is answered `signed: false` (the contract has no validation error);
 * the signature is never echoed back or logged.
 */
class SignRegistrationCardRequest extends FormRequest
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
            'terms_version' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'signature_png' => ['required', 'string', 'max:1500000'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException((new KioskSignedResource(false))->response());
    }
}

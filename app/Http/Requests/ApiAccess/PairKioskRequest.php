<?php

namespace App\Http\Requests\ApiAccess;

use App\Http\Responses\ApiError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Body of `POST /api/kiosk/v1/pair`. A missing or malformed code is answered like a wrong code, so the contract needs no extra error code.
 */
class PairKioskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pairing_code' => ['required', 'string', 'max:32'],
            'device_name' => ['nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiError::response('kiosk_unauthorized', __('That pairing code is not valid.'), 401));
    }
}

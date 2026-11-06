<?php

namespace App\Http\Requests\Kiosk;

use App\Http\Responses\ApiError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['uid' => ['sometimes', 'required', 'string', 'max:40']];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(ApiError::response('card_unknown', __('This card is not known. Please see the front desk.'), 404));
    }
}

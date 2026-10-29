<?php

namespace App\Http\Requests\DoorLocks;

use App\Enums\KeyportApiError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base for the Keyport API requests. A bad request answers in the contract's error shape. Authentication is the
 * device middleware's job.
 */
abstract class KeyportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(KeyportApiError::InvalidRequest->response($validator->errors()->first()));
    }
}

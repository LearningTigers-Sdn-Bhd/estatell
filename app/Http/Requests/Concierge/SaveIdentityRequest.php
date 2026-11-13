<?php

namespace App\Http\Requests\Concierge;

use App\Http\Requests\Kiosk\StoreStayIdentityRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The ID a guest typed on the Concierge page: the fields of the kiosk's ID, plus an optional photo of each side. A bad
 * form is an ordinary validation error, because the page shows it on the field.
 */
class SaveIdentityRequest extends StoreStayIdentityRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Blank is allowed with `keep_saved_document`: the action then keeps the guest's saved document of that kind.
            'number' => ['required_without:keep_saved_document', 'nullable', 'string', 'min:4', 'max:40', Rule::when($this->input('kind') === 'my_kad', ['digits:12'])],
            'keep_saved_document' => ['nullable', 'boolean'],
            'scan' => ['nullable', 'image', 'max:8192'],
            'scan_back' => ['nullable', 'image', 'max:8192'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw (new ValidationException($validator))->errorBag($this->errorBag)->redirectTo($this->getRedirectUrl());
    }
}

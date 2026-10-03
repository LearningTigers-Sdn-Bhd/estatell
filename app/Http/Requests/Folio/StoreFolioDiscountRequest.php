<?php

namespace App\Http\Requests\Folio;

use Illuminate\Contracts\Validation\ValidationRule;

class StoreFolioDiscountRequest extends QuoteFolioDiscountRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'fingerprint' => ['required', 'string', 'size:64'],
        ];
    }
}

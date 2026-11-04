<?php

namespace App\Http\Requests\Kiosk;

use App\Http\Resources\Kiosk\V1\KioskAcceptedResource;
use App\Services\Guests\CountryOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The guest's corrections on the confirm screen. Both fields are optional. A body that is not valid is answered
 * `accepted: false` (the contract has no validation error); it is not echoed back or logged.
 */
class UpdateStayGuestRequest extends FormRequest
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
            'full_name' => ['nullable', 'string', 'max:150'],
            'nationality' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function fullName(): ?string
    {
        return $this->filled('full_name') ? trim($this->string('full_name')->toString()) : null;
    }

    /**
     * The ISO code, or null when the kiosk sent none or one Estatell does not know.
     */
    public function nationalityCode(): ?string
    {
        $code = strtoupper(trim($this->string('nationality')->toString()));

        return in_array($code, app(CountryOptions::class)->codes(), true) ? $code : null;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException((new KioskAcceptedResource(false))->response());
    }
}

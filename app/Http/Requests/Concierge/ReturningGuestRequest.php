<?php

namespace App\Http\Requests\Concierge;

use App\Services\Guests\IdentityNumber;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The ID number and date of birth a walk-in gives to be recognised as a returning guest.
 */
class ReturningGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('number'))) {
            $this->merge(['number' => app(IdentityNumber::class)->normalize($this->input('number'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'min:4', 'max:40'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
        ];
    }

    public function dateOfBirth(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('date_of_birth')->toString());
    }
}

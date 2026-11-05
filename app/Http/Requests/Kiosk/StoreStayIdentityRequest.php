<?php

namespace App\Http\Requests\Kiosk;

use App\Actions\Kiosk\KioskIdentityResult;
use App\Data\Guests\IdentityDocumentInputData;
use App\Data\Kiosk\KioskIdentityData;
use App\Enums\Gender;
use App\Enums\IdentityDocumentType;
use App\Http\Resources\Kiosk\V1\KioskIdentityResource;
use App\Services\Guests\CountryOptions;
use App\Services\Guests\IdentityNumber;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * The ID a kiosk read. Kind, number and name must be right; the contract has no validation error, so a bad body is
 * answered `accepted: false` and nothing is saved. The optional fields (date of birth, gender, expiry) that are
 * missing or do not parse are left out, and a nationality Estatell does not know is treated as not known. A rejected
 * body is never echoed back or logged: it holds personal data.
 */
class StoreStayIdentityRequest extends FormRequest
{
    private const string FIRST_DATE_OF_BIRTH = '1900-01-01';

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
            'kind' => ['required', 'string', Rule::in(['my_kad', 'passport'])],
            'number' => ['required', 'string', 'min:4', 'max:40', Rule::when($this->input('kind') === 'my_kad', ['digits:12'])],
            'full_name' => ['required', 'string', 'max:150', 'regex:/\S/'],
            'nationality' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function toIdentityData(): KioskIdentityData
    {
        $type = $this->string('kind')->toString() === 'my_kad' ? IdentityDocumentType::MyKad : IdentityDocumentType::Passport;
        $nationality = $this->nationalityCode($this->input('nationality'));

        return new KioskIdentityData(
            document: new IdentityDocumentInputData(
                type: $type,
                number: $this->string('number')->toString(),
                issuingCountryCode: $type === IdentityDocumentType::MyKad ? 'MY' : $nationality,
                expiresOn: $this->day($this->input('expires_on'))?->toDateString(),
            ),
            fullName: trim($this->string('full_name')->toString()),
            nationalityCode: $nationality,
            dateOfBirth: $this->dateOfBirth($this->input('date_of_birth')),
            gender: is_string($this->input('gender')) ? Gender::tryFrom(strtolower(trim($this->input('gender')))) : null,
        );
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException((new KioskIdentityResource(KioskIdentityResult::refused()))->response());
    }

    private function nationalityCode(mixed $value): ?string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';

        return in_array($code, app(CountryOptions::class)->codes(), true) ? $code : null;
    }

    /**
     * A real calendar day written `YYYY-MM-DD`, or null.
     */
    private function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }

    private function dateOfBirth(mixed $value): ?CarbonImmutable
    {
        $day = $this->day($value);

        return $day !== null && $day->toDateString() >= self::FIRST_DATE_OF_BIRTH && $day->isPast() ? $day : null;
    }
}

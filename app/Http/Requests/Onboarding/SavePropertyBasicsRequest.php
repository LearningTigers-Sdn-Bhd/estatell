<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\PropertyType;
use App\Http\Requests\Property\SavePropertyBusinessRequest;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use App\Services\Property\CountryTimeZones;
use App\Services\Property\CurrencyOptions;
use App\Services\Property\MalaysianStates;
use App\Services\Property\SettingsLock;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class SavePropertyBasicsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $country = $this->input('country_code');

        $this->merge([
            'country_code' => is_string($country) ? mb_strtoupper(trim($country)) : $country,
            'licences' => SavePropertyBusinessRequest::trimLicences($this->input('licences')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Country, currency and time zone are left out once setup started or the property is live: the saved values stay.
     * For Malaysia currency and time zone are left out too, because the profile fixes them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(SettingsLock $settingsLock): array
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        $locked = $settingsLock->locksCountry($property);
        $country = $locked ? $property->country_code : $this->input('country_code');

        return [
            'type' => ['required', Rule::enum(PropertyType::class)],
            ...$this->regionRules($locked),
            ...self::identityRules(),
            ...($country === 'MY' ? ['state_code' => ['nullable', 'string', Rule::in(MalaysianStates::names())]] : []),
            ...SavePropertyBusinessRequest::businessRules($property),
        ];
    }

    /**
     * The name and address rules, shared with the Profile tab of the Details page.
     *
     * @return array<string, array<int, string>>
     */
    public static function identityRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state_code' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function regionRules(bool $locked): array
    {
        if ($locked) {
            return [
                'country_code' => ['exclude'],
                'currency' => ['exclude'],
                'time_zone' => ['exclude'],
            ];
        }

        return [
            'country_code' => ['required', 'string', Rule::in(app(CountryOptions::class)->codes())],
            'currency' => ['exclude_if:country_code,MY', 'required', 'string', Rule::in(CurrencyOptions::codes())],
            'time_zone' => [
                'exclude_if:country_code,MY',
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! in_array($value, CountryTimeZones::for((string) $this->input('country_code')), true)) {
                        $fail(__('Choose one of the time zones of the selected country.'));
                    }
                },
            ],
        ];
    }

    /**
     * The validated type, name, address, country, currency and time zone fields.
     *
     * @return array<string, mixed>
     */
    public function basics(): array
    {
        return array_diff_key($this->validated(), array_flip(['legal_name', 'registration_number', 'tin', 'licences']));
    }

    /**
     * @return array{legal_name: string|null, registration_number: string|null, tin: string|null}
     */
    public function business(): array
    {
        return SavePropertyBusinessRequest::businessFrom($this->validated());
    }

    /**
     * @return list<array{id: int|null, issuer: string, number: string, expires_on: string|null, show_on_documents: bool}>
     */
    public function licences(): array
    {
        return SavePropertyBusinessRequest::licencesFrom($this->validated('licences'));
    }
}

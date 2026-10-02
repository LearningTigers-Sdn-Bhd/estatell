<?php

namespace App\Http\Requests\Guests;

use App\Enums\Gender;
use App\Enums\Locale;
use App\Services\Guests\CountryOptions;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * The validation rules of a guest's profile fields, shared by the guest form and the booking form, so the two cannot
 * drift apart. A phone number typed without a country code is read as a number of the property's country.
 */
class GuestProfileRules
{
    /**
     * The profile fields the forms collect.
     *
     * @var list<string>
     */
    public const array FIELDS = [
        'full_name', 'email', 'phone', 'nationality_code', 'date_of_birth', 'gender', 'preferred_locale',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code',
    ];

    /**
     * @param  bool  $nameRequired  False when the guest may be an existing one and the name is not typed.
     * @return array<string, array<mixed>> Rules keyed by field name.
     */
    public static function for(string $phoneRegion, bool $nameRequired = true): array
    {
        $countries = app(CountryOptions::class)->codes();

        return [
            'full_name' => [$nameRequired ? 'required' : 'nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', (new Phone)->country($phoneRegion)->international()],
            'nationality_code' => ['nullable', Rule::in($countries)],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'preferred_locale' => ['nullable', Rule::enum(Locale::class)],
            'address_line1' => ['nullable', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', Rule::in($countries)],
        ];
    }

    /**
     * The rules with a prefix on every key, for a nested form such as `guests.*.`.
     *
     * @return array<string, array<mixed>>
     */
    public static function prefixed(string $prefix, string $phoneRegion, bool $nameRequired = true): array
    {
        $rules = [];

        foreach (self::for($phoneRegion, $nameRequired) as $field => $fieldRules) {
            $rules[$prefix.$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * The profile fields of validated input, with the phone number in E.164. Other keys are dropped.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function attributes(array $validated, string $phoneRegion): array
    {
        $attributes = array_intersect_key($validated, array_flip(self::FIELDS));

        if (filled($attributes['phone'] ?? null)) {
            $attributes['phone'] = (new PhoneNumber($attributes['phone'], $phoneRegion))->formatE164();
        }

        return $attributes;
    }
}

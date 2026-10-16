<?php

namespace App\Data\Property;

use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\PropertyLicence;
use App\Services\Guests\CountryOptions;
use App\Services\Property\CountryChangeCounts;
use App\Services\Property\CountryProfiles;
use App\Services\Property\SettingsLock;
use Spatie\LaravelData\Data;

/**
 * The Basics step of onboarding: identity, country, currency and time zone, and the optional business details. `locked`
 * is true once setup started or the property is live: country, currency and time zone then show read-only.
 * `canChangeCountry` shows the Change country button, and `countryChange` holds the counts its dialog lists.
 */
class PropertyBasicsData extends Data
{
    /**
     * @param  list<PropertyLicenceData>  $licences
     */
    public function __construct(
        public string $name,
        public ?PropertyType $type,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $stateCode,
        public ?string $postalCode,
        public string $countryCode,
        public ?string $countryName,
        public string $currency,
        public string $timeZone,
        public bool $locked,
        public bool $canChangeCountry,
        public ?CountryChangeCountsData $countryChange,
        public ?string $legalName,
        public ?string $registrationNumber,
        public ?string $tin,
        public array $licences,
    ) {}

    public static function fromModel(Property $property): self
    {
        $countryCode = $property->country_code ?? 'MY';
        $profile = CountryProfiles::for($countryCode);
        $settings = $property->settings;
        $locked = app(SettingsLock::class)->locksCountry($property);
        $canChangeCountry = $locked && app(SettingsLock::class)->canChangeCountry($property);

        return new self(
            name: $property->name,
            type: $property->type,
            addressLine1: $property->address_line1,
            addressLine2: $property->address_line2,
            city: $property->city,
            stateCode: $property->state_code,
            postalCode: $property->postal_code,
            countryCode: $countryCode,
            countryName: app(CountryOptions::class)->name($countryCode),
            currency: $settings?->currency->getCurrencyCode() ?? $profile->suggestedCurrency() ?? '',
            timeZone: $settings->time_zone ?? $profile->timeZone(),
            locked: $locked,
            canChangeCountry: $canChangeCountry,
            countryChange: $canChangeCountry ? app(CountryChangeCounts::class)->forProperty($property) : null,
            legalName: $property->legal_name,
            registrationNumber: $property->registration_number,
            tin: $property->tin,
            licences: $property->licences()->ordered()->get()
                ->map(fn (PropertyLicence $licence): PropertyLicenceData => PropertyLicenceData::fromModel($licence))
                ->all(),
        );
    }
}

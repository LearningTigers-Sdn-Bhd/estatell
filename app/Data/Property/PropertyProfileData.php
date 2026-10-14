<?php

namespace App\Data\Property;

use App\Models\Property;
use App\Services\Guests\CountryOptions;
use App\Services\Property\CountryProfiles;
use Spatie\LaravelData\Data;

/**
 * The property identity on the Profile tab, and the values the printing preview draws its letterhead from.
 */
class PropertyProfileData extends Data
{
    public function __construct(
        public string $name,
        public ?string $typeLabel,
        public ?string $countryName,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $stateCode,
        public ?string $postalCode,
        public ?string $contactPhone,
        public ?string $contactEmail,
        public ?string $registrationNumber,
        public string $registrationNumberLabel,
        public ?string $tin,
        public string $tinLabel,
        public ?string $logoUrl,
    ) {}

    public static function fromModel(Property $property): self
    {
        $logo = $property->getFirstMedia(Property::LOGO);
        $profile = CountryProfiles::for((string) $property->country_code);

        return new self(
            name: $property->name,
            typeLabel: $property->type?->label(),
            countryName: app(CountryOptions::class)->name($property->country_code),
            addressLine1: $property->address_line1,
            addressLine2: $property->address_line2,
            city: $property->city,
            stateCode: $property->state_code,
            postalCode: $property->postal_code,
            contactPhone: $property->contact_phone,
            contactEmail: $property->contact_email,
            registrationNumber: $property->registration_number,
            registrationNumberLabel: $profile->registrationNumberLabel(),
            tin: $property->tin,
            tinLabel: $profile->tinLabel(),
            logoUrl: $logo === null ? null : route('settings.logo', ['property' => $property->code, 'v' => $logo->id], absolute: false),
        );
    }
}

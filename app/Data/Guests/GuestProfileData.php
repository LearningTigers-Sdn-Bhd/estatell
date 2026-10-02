<?php

namespace App\Data\Guests;

use App\Models\Guest;
use Spatie\LaravelData\Data;

/**
 * The details of a guest that a form fills from a picked or matched guest: the personal details, the address and the
 * blacklist reason. Name, phone and email are on the result itself. Only staff who may view guests receive it.
 */
class GuestProfileData extends Data
{
    public function __construct(
        public ?string $nationalityCode,
        public ?string $dateOfBirth,
        public ?string $gender,
        public ?string $preferredLocale,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $state,
        public ?string $postalCode,
        public ?string $countryCode,
        public ?string $blacklistReason,
    ) {}

    public static function fromModel(Guest $guest): self
    {
        return new self(
            nationalityCode: $guest->nationality_code,
            dateOfBirth: $guest->date_of_birth?->toDateString(),
            gender: $guest->gender?->value,
            preferredLocale: $guest->preferred_locale?->value,
            addressLine1: $guest->address_line1,
            addressLine2: $guest->address_line2,
            city: $guest->city,
            state: $guest->state,
            postalCode: $guest->postal_code,
            countryCode: $guest->country_code,
            blacklistReason: $guest->blacklist_reason,
        );
    }
}

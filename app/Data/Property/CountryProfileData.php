<?php

namespace App\Data\Property;

use App\Services\Property\CountryProfile;
use Spatie\LaravelData\Data;

/**
 * What the Basics form shows for a country: the business number labels, the note under the form, and whether currency
 * and time zone are fixed by the country.
 */
class CountryProfileData extends Data
{
    public function __construct(
        public string $registrationNumberLabel,
        public string $tinLabel,
        public string $notice,
        public bool $fixedRegion,
    ) {}

    public static function fromProfile(CountryProfile $profile): self
    {
        return new self(
            registrationNumberLabel: $profile->registrationNumberLabel(),
            tinLabel: $profile->tinLabel(),
            notice: $profile->basicsNotice(),
            fixedRegion: $profile->hasFixedRegion(),
        );
    }
}

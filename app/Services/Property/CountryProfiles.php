<?php

namespace App\Services\Property;

class CountryProfiles
{
    /** Malaysia has its own profile. Every other country gets the generic one, so this never throws. */
    public static function for(string $countryCode): CountryProfile
    {
        $countryCode = mb_strtoupper($countryCode);

        return match ($countryCode) {
            'MY' => new MalaysiaProfile,
            default => new GenericProfile($countryCode),
        };
    }
}

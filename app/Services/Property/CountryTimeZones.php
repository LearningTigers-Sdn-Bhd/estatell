<?php

namespace App\Services\Property;

use DateTimeZone;

/**
 * The time zones a property in a country can pick: the country's zones, or UTC for a country that has none.
 */
class CountryTimeZones
{
    /** @return list<string> */
    public static function for(string $countryCode): array
    {
        $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, mb_strtoupper($countryCode));

        return $zones === [] ? ['UTC'] : $zones;
    }
}

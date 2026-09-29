<?php

namespace App\Services\Property;

use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * Today's calendar date in the property's time zone.
 * Room blocks use it until business dates exist; then they switch to CurrentBusinessDate.
 */
class PropertyToday
{
    public function date(Property $property): CarbonImmutable
    {
        $timeZone = $property->settings?->time_zone ?? config('app.timezone');

        return CarbonImmutable::now($timeZone)->startOfDay();
    }
}

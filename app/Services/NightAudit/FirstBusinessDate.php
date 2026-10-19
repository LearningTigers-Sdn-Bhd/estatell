<?php

namespace App\Services\NightAudit;

use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * The go-live date of a property: its first business date, the earliest `business_dates.date`. Null until the property
 * goes live. Past and migrated stays use it to tell a late entry from a stay that began in the old system.
 */
class FirstBusinessDate
{
    public function date(Property $property): ?CarbonImmutable
    {
        $date = $property->businessDates()->min('date');

        return $date === null ? null : CarbonImmutable::parse($date)->startOfDay();
    }
}

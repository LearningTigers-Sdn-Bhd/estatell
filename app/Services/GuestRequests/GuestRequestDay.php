<?php

namespace App\Services\GuestRequests;

use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;

/**
 * The day a request is opened or resolved on: the property's open business date, or the calendar day in the property's
 * time zone before business dates exist. A business date is not the calendar date near midnight, so the KPIs of a closed
 * date stay fixed. Reads only.
 */
class GuestRequestDay
{
    public function __construct(private CurrentBusinessDate $businessDate) {}

    public function for(Property $property): CarbonImmutable
    {
        $property->loadMissing('settings');

        $date = $this->businessDate->date($property);

        return $date !== null ? $date->toImmutable()->startOfDay() : CarbonImmutable::now($property->settings->time_zone)->startOfDay();
    }
}

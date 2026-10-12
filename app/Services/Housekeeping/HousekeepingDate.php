<?php

namespace App\Services\Housekeeping;

use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;

/**
 * The date the housekeeping board works on: the open business date, or the property's local date before business
 * dates exist.
 */
class HousekeepingDate
{
    public function __construct(private CurrentBusinessDate $businessDate, private PropertyToday $propertyToday) {}

    public function for(Property $property): CarbonImmutable
    {
        return $this->businessDate->date($property) ?? $this->propertyToday->date($property);
    }
}

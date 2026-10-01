<?php

namespace App\Services\NightAudit;

use App\Models\BusinessDate;
use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * The business date a property is trading on: its Open date, or its Auditing date while the audit runs.
 * Every posting uses it, never the calendar date. Null until the property goes live.
 */
class CurrentBusinessDate
{
    public function get(Property $property): ?BusinessDate
    {
        return $property->businessDates()->current()->first();
    }

    public function date(Property $property): ?CarbonImmutable
    {
        $businessDate = $this->get($property);

        return $businessDate === null ? null : CarbonImmutable::parse($businessDate->date->toDateString());
    }
}

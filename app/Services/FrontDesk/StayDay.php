<?php

namespace App\Services\FrontDesk;

use App\Enums\StayType;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;

/**
 * The day the front desk works on. While the night audit is pending, the property's calendar date is exactly 1 day
 * after the business date (the date is Open or Auditing, an Overdue audit is 2 or more days behind and is not
 * pending), and a guest who arrives or leaves after midnight belongs to the calendar date. Check-out, early check-out,
 * late check-out and the unit menu use the calendar date while the audit is pending, otherwise the business date.
 * Check-in uses the calendar date only for a unit that arrives on it, so a guest booked for the night of the business
 * date who arrives after midnight still checks in for that night. A day use unit keeps the business date. Every
 * posting still uses the business date. It reads only. See
 * docs/18-front-desk/01-check-in-and-check-out.md#night-audit-pending.
 */
class StayDay
{
    public function __construct(private PropertyToday $propertyToday) {}

    /**
     * Whether the calendar date is exactly 1 day after the business date.
     */
    public function isAuditPending(Property $property, CarbonImmutable $businessDate): bool
    {
        $today = $this->propertyToday->date($property->loadMissing('settings'));

        return $today->toDateString() === $businessDate->addDay()->toDateString();
    }

    /**
     * The day a check-out works on: the calendar date while the audit is pending, otherwise the business date.
     */
    public function forCheckOut(Property $property, CarbonImmutable $businessDate): CarbonImmutable
    {
        return $this->isAuditPending($property, $businessDate) ? $businessDate->addDay() : $businessDate;
    }

    /**
     * The day a check-in works on: the calendar date when the audit is pending and the unit arrives on it, otherwise
     * the business date. A day use unit always gets the business date.
     */
    public function forCheckIn(ReservationUnit $unit, CarbonImmutable $businessDate): CarbonImmutable
    {
        if ($unit->stay_type === StayType::DayUse) {
            return $businessDate;
        }

        $calendar = $businessDate->addDay();

        if ($unit->arrival_date->toDateString() !== $calendar->toDateString()) {
            return $businessDate;
        }

        return $this->isAuditPending($unit->loadMissing('property.settings')->property, $businessDate) ? $calendar : $businessDate;
    }
}

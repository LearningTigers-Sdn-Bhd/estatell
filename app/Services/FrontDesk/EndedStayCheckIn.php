<?php

namespace App\Services\FrontDesk;

use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The rule for checking in a stay the front desk did not log in time: the unit is Confirmed, it is not a day use and
 * its departure date is the business date. The menu, the preview and `CheckInEndedStay` all ask this one class. It
 * reads only. See docs/18-front-desk/01-check-in-and-check-out.md#check-in-after-the-stay-ended.
 */
class EndedStayCheckIn
{
    public function isOffered(ReservationUnit $unit, CarbonInterface $businessDate): bool
    {
        return $unit->status === UnitStatus::Confirmed
            && $unit->stay_type !== StayType::DayUse
            && $unit->departure_date->toDateString() === $businessDate->toDateString();
    }

    public function lastNight(ReservationUnit $unit): CarbonImmutable
    {
        return $unit->departure_date->toImmutable()->startOfDay()->subDay();
    }

    /**
     * The moment the guest arrived, in the application time zone. `$time` (`H:i`) is a clock time at the property. A
     * time before noon is the morning after the last night (a 02:00 arrival is on the departure date), and noon or
     * later is on the last night.
     */
    public function arrivedAt(ReservationUnit $unit, string $time): CarbonImmutable
    {
        $date = $time < '12:00' ? $this->lastNight($unit)->addDay() : $this->lastNight($unit);
        $timeZone = $unit->loadMissing('property.settings')->property->settings->time_zone ?? config('app.timezone');

        return CarbonImmutable::parse($date->toDateString().' '.$time, $timeZone)->setTimezone(config('app.timezone'));
    }
}

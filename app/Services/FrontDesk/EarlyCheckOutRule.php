<?php

namespace App\Services\FrontDesk;

use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;

/**
 * When a unit can check out before its departure date: it is CheckedIn, the business date is before the departure
 * date and after the arrival date. A unit that arrived on the business date has no night to keep, because moving
 * its departure to today would end the stay where it began. The unit menu, the group check-out page, the preview and
 * `EarlyCheckOut` all ask this one class, so they never disagree. It reads only.
 * See docs/18-front-desk/01-check-in-and-check-out.md#early-check-out.
 */
class EarlyCheckOutRule
{
    /**
     * The refusal code, or null when the unit can check out early on the business date.
     *
     * @param  string  $businessDate  `Y-m-d`
     */
    public function refusal(ReservationUnit $unit, string $businessDate): ?string
    {
        if ($unit->status !== UnitStatus::CheckedIn) {
            return UnitStayResult::REFUSAL_INVALID_STATUS;
        }

        if ($businessDate >= $unit->departure_date->toDateString()) {
            return UnitStayResult::REFUSAL_NOT_EARLY;
        }

        if ($businessDate <= $unit->arrival_date->toDateString()) {
            return UnitStayResult::REFUSAL_ARRIVED_TODAY;
        }

        return null;
    }

    /**
     * @param  string  $businessDate  `Y-m-d`
     */
    public function isOffered(ReservationUnit $unit, string $businessDate): bool
    {
        return $this->refusal($unit, $businessDate) === null;
    }
}

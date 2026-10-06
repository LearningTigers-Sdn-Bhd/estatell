<?php

namespace App\Actions\FrontDesk;

use App\Models\ReservationUnit;
use App\Models\User;
use Carbon\CarbonInterface;

class ExtendStay
{
    public function __construct(private ChangeUnitDates $changeUnitDates) {}

    /**
     * Move the departure of a unit to a later date and keep its arrival: `ChangeUnitDates` with the arrival locked.
     * A date that is not later is refused with `not_an_extension`, writing nothing. The night audit's readiness fix
     * "Extend stay" calls this. The arguments after the date are those of `ChangeUnitDates`, and an extension never
     * has a fee. Permissions are checked by the route. See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, CarbonInterface $newDeparture, ?string $restrictionOverrideReason = null): StayDatesResult
    {
        if ($newDeparture->startOfDay()->lte($unit->departure_date->startOfDay())) {
            return StayDatesResult::refused(UnitStayResult::REFUSAL_NOT_AN_EXTENSION);
        }

        return ($this->changeUnitDates)($actor, $unit, $unit->arrival_date, $newDeparture, restrictionOverrideReason: $restrictionOverrideReason);
    }
}

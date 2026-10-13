<?php

namespace App\Services\Boats;

use App\Actions\Boats\BoatTripResult;
use App\Enums\BoatDirection;
use App\Enums\StayType;
use App\Models\ReservationUnit;

/**
 * Whether a unit can take a boat trip in one direction, and why not. The Actions refuse with it and the reservation page
 * shows Change from it, so both read the same rule. Reads only.
 */
class BoatTripAccess
{
    /**
     * The refusal code `SetBoatTrip` and `ClearBoatTrip` return for the unit, or null when it can take the trip. A day use
     * unit has no boat trips, because its times are its start and end.
     */
    public function refusalFor(ReservationUnit $unit, BoatDirection $direction): ?string
    {
        if ($unit->stay_type === StayType::DayUse) {
            return BoatTripResult::REFUSAL_DAY_USE;
        }

        if (! in_array($unit->status, $direction->unitStatuses(), true)) {
            return BoatTripResult::REFUSAL_UNIT_NOT_OPEN;
        }

        return null;
    }
}

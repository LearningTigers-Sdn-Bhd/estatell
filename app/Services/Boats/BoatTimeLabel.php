<?php

namespace App\Services\Boats;

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\StayType;
use App\Models\ReservationUnit;

/**
 * The short boat text of a unit for the front desk lists: "09:00 boat", "Charter 10:30", "Own 11:30" or "—". The time is
 * the unit's expected time, which the trip set. Load the unit's `boatTrips` first. Reads only.
 */
class BoatTimeLabel
{
    public const NONE = '—';

    public function forUnit(ReservationUnit $unit, BoatDirection $direction): string
    {
        $trip = $unit->stay_type === StayType::DayUse
            ? null
            : $unit->boatTrips->firstWhere('direction', $direction);

        if ($trip === null) {
            return self::NONE;
        }

        $time = substr($direction === BoatDirection::In ? $unit->arrival_time : $unit->departure_time, 0, 5);

        return match ($trip->mode) {
            BoatMode::Scheduled => __(':time boat', ['time' => $time]),
            BoatMode::Charter => __('Charter :time', ['time' => $time]),
            BoatMode::Own => __('Own :time', ['time' => $time]),
        };
    }
}

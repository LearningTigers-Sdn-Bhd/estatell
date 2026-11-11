<?php

namespace App\Services\Boats;

use App\Enums\BoatDirection;
use App\Enums\SailingKind;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use Carbon\CarbonInterface;

/**
 * The property-side time a trip takes on a date: the main sailing's own time when the date has one, else the timetable.
 * `SetBoatTrip` and `SyncBoatTripsToStay` read it, so a trip booked or moved onto a date with a changed time gets that time.
 * Reads only. See docs/24-boats/04-sailings.md#times.
 */
class SailingTimes
{
    /** `HH:MM:SS`: the arrival for Boat in, the departure for Boat out. */
    public function forTrip(BoatDeparture $departure, CarbonInterface $date): string
    {
        $column = $departure->direction === BoatDirection::In ? 'arrives_at' : 'leaves_at';
        $own = BoatSailing::query()
            ->where('boat_departure_id', $departure->id)
            ->whereDate('date', $date->toDateString())
            ->where('kind', SailingKind::Guests->value)
            ->orderBy('id')
            ->value($column);

        return substr((string) ($own ?? $departure->direction->propertyTime($departure)), 0, 5).':00';
    }
}

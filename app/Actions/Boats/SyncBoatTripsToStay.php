<?php

namespace App\Actions\Boats;

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Services\Boats\SailingTimes;

class SyncBoatTripsToStay
{
    public function __construct(private SailingTimes $sailingTimes) {}

    /**
     * Keep a unit's boat trips in step after its stay dates moved. A trip sits on the unit's arrival date (Boat in) or
     * departure date (Boat out). When that date moved, the trip is no longer on the sailing it was moved to and the guest
     * has not boarded: `boat_sailing_id` is cleared, the tick is cleared, and a scheduled trip's unit time goes back to the
     * main sailing's time on the new date (the timetable time, else that date's own time). A charter or own boat trip keeps
     * the time staff typed. A guest already checked in keeps their arrival time. Run it inside the caller's transaction,
     * after the unit's dates are saved; the caller holds the unit lock and logs the stay change (this logs nothing).
     * See docs/24-boats/02-boat-trips.md.
     *
     * @param  string  $previousArrival  `Y-m-d`, the unit's arrival date before the change.
     * @param  string  $previousDeparture  `Y-m-d`, the unit's departure date before the change.
     */
    public function __invoke(ReservationUnit $unit, string $previousArrival, string $previousDeparture): void
    {
        $trips = UnitBoatTrip::query()->with('boatDeparture')->where('reservation_unit_id', $unit->id)->get();

        foreach ($trips as $trip) {
            $previous = $trip->direction === BoatDirection::In ? $previousArrival : $previousDeparture;
            $current = $unit->{$trip->direction->tripDateColumn()}->toDateString();

            if ($previous === $current) {
                continue;
            }

            $trip->forceFill(['boat_sailing_id' => null, 'ticked_at' => null, 'ticked_by' => null])->save();

            if ($trip->mode !== BoatMode::Scheduled || $trip->boatDeparture === null
                || ! in_array($unit->status, $trip->direction->unitStatuses(), true)) {
                continue;
            }

            $column = $trip->direction === BoatDirection::In ? 'arrival_time' : 'departure_time';
            $time = $this->sailingTimes->forTrip($trip->boatDeparture, $unit->{$trip->direction->tripDateColumn()});

            if (substr((string) $unit->{$column}, 0, 5) !== substr($time, 0, 5)) {
                $unit->forceFill([$column => $time])->save();
            }
        }
    }
}

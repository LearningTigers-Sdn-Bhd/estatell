<?php

namespace App\Actions\Boats;

use App\Enums\BoatDirection;
use App\Features\Boats;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class ClearBoatTrip
{
    /**
     * Remove one direction of a unit's boat trip ("Not set"). The unit's expected time stays as it is. A direction with
     * no trip does nothing and logs nothing. Same unit checks as `SetBoatTrip`. See docs/24-boats/02-boat-trips.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, BoatDirection $direction): BoatTripResult
    {
        $property = Property::query()->findOrFail($unit->property_id);

        if (! Feature::for($property)->active(Boats::class)) {
            return BoatTripResult::refused(BoatTripResult::REFUSAL_BOATS_OFF);
        }

        return DB::transaction(function () use ($actor, $unit, $direction): BoatTripResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if (! in_array($locked->status, $direction->unitStatuses(), true)) {
                return BoatTripResult::refused(BoatTripResult::REFUSAL_UNIT_NOT_OPEN);
            }

            $trip = UnitBoatTrip::query()
                ->where('reservation_unit_id', $locked->id)
                ->where('direction', $direction->value)
                ->first();

            if ($trip === null) {
                return BoatTripResult::done();
            }

            $this->syncFare($locked, $trip);

            $trip->delete();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.boat_trip_cleared')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'direction' => $direction->value,
                    'mode' => $trip->mode->value,
                ])
                ->log('Boat trip cleared');

            return BoatTripResult::done();
        });
    }

    /**
     * Fare rows for the trip go here. The boat fare item fills this in; until then a trip adds nothing.
     */
    private function syncFare(ReservationUnit $unit, UnitBoatTrip $trip): void {}
}

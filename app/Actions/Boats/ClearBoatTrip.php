<?php

namespace App\Actions\Boats;

use App\Enums\BoatDirection;
use App\Features\Boats;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;
use App\Services\Boats\BoatTripAccess;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class ClearBoatTrip
{
    public function __construct(
        private BoatTripAccess $boatTripAccess,
        private SyncBoatFare $syncBoatFare,
    ) {}

    /**
     * Remove one direction of a unit's boat trip ("Not set"). The unit's expected time stays as it is. A direction with
     * no trip does nothing and logs nothing. The unposted fare rows go with it, in the same transaction; a posted fare row
     * is kept and the result says so (`postedFareKept`). Same unit checks as `SetBoatTrip`. See docs/24-boats/02-boat-trips.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, BoatDirection $direction): BoatTripResult
    {
        $property = Property::query()->findOrFail($unit->property_id);

        if (! Feature::for($property)->active(Boats::class)) {
            return BoatTripResult::refused(BoatTripResult::REFUSAL_BOATS_OFF);
        }

        try {
            return DB::transaction(fn (): BoatTripResult => $this->clear($actor, $unit, $direction));
        } catch (BoatTripRefused $refused) {
            return BoatTripResult::refused($refused->refusal);
        }
    }

    private function clear(User $actor, ReservationUnit $unit, BoatDirection $direction): BoatTripResult
    {
        $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

        $refusal = $this->boatTripAccess->refusalFor($locked, $direction);

        if ($refusal !== null) {
            return BoatTripResult::refused($refusal);
        }

        $trip = UnitBoatTrip::query()
            ->where('reservation_unit_id', $locked->id)
            ->where('direction', $direction->value)
            ->first();

        if ($trip === null) {
            return BoatTripResult::done();
        }

        $fare = ($this->syncBoatFare)($actor, $locked, $trip, null);

        if ($fare->isRefused()) {
            throw new BoatTripRefused((string) $fare->refusal);
        }

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

        return BoatTripResult::done($fare->postedFareKept);
    }
}

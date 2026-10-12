<?php

namespace App\Actions\Boats;

use App\Models\BoatDeparture;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use Illuminate\Support\Facades\DB;

class DeleteBoatDeparture
{
    public function __construct(private BoatTripUsage $boatTripUsage) {}

    /**
     * Delete a departure that no trip has ever used. A trip of any status refuses it (`departure_in_use`), so the switch
     * is the way to stop using it. The restrict foreign key from the trips is the database's own guard.
     */
    public function __invoke(User $actor, BoatDeparture $boatDeparture): BoatDepartureResult
    {
        return DB::transaction(function () use ($actor, $boatDeparture): BoatDepartureResult {
            $boatDeparture = BoatDeparture::query()->lockForUpdate()->findOrFail($boatDeparture->id);

            if ($this->boatTripUsage->isUsed($boatDeparture)) {
                return BoatDepartureResult::refused(BoatDepartureResult::REFUSAL_DEPARTURE_IN_USE);
            }

            $propertyId = $boatDeparture->property_id;
            $direction = $boatDeparture->direction->value;
            $leavesAt = substr($boatDeparture->leaves_at, 0, 5);

            $boatDeparture->delete();

            activity()
                ->causedBy($actor)
                ->event('boat_departure.deleted')
                ->withProperties(['property_id' => $propertyId, 'direction' => $direction, 'leaves_at' => $leavesAt])
                ->log('Boat departure deleted');

            return BoatDepartureResult::done();
        });
    }
}

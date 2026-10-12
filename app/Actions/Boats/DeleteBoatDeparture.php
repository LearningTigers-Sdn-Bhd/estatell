<?php

namespace App\Actions\Boats;

use App\Models\BoatDeparture;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteBoatDeparture
{
    /**
     * Delete a departure. Nothing uses a departure yet, so this always works. Once boat trips exist, the restrict
     * foreign key from their rows is the guard, and the check moves here.
     */
    public function __invoke(User $actor, BoatDeparture $boatDeparture): void
    {
        DB::transaction(function () use ($actor, $boatDeparture): void {
            $boatDeparture = BoatDeparture::query()->lockForUpdate()->findOrFail($boatDeparture->id);

            $propertyId = $boatDeparture->property_id;
            $direction = $boatDeparture->direction->value;
            $leavesAt = substr($boatDeparture->leaves_at, 0, 5);

            $boatDeparture->delete();

            activity()
                ->causedBy($actor)
                ->event('boat_departure.deleted')
                ->withProperties(['property_id' => $propertyId, 'direction' => $direction, 'leaves_at' => $leavesAt])
                ->log('Boat departure deleted');
        });
    }
}

<?php

namespace App\Actions\Boats;

use App\Models\UnitBoatTrip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ticks a trip on the Boat manifest: the guests of a Boat in trip have arrived, or those of a Boat out trip have left.
 * It records who ticked and when, and does not check anyone in or out. A trip already in the wanted state is not logged
 * again. See docs/24-boats/03-boat-manifest.md#tick.
 */
class SetBoatTripTick
{
    public function __invoke(User $actor, UnitBoatTrip $trip, bool $ticked): void
    {
        DB::transaction(function () use ($actor, $trip, $ticked): void {
            $locked = UnitBoatTrip::query()->with('reservationUnit.reservation')->lockForUpdate()->findOrFail($trip->id);

            if (($locked->ticked_at !== null) === $ticked) {
                return;
            }

            $locked->forceFill([
                'ticked_at' => $ticked ? now() : null,
                'ticked_by' => $ticked ? $actor->id : null,
            ])->save();

            activity()
                ->performedOn($locked->reservationUnit->reservation)
                ->causedBy($actor)
                ->event('reservation.boat_trip_ticked')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->reservation_unit_id,
                    'direction' => $locked->direction->value,
                    'ticked' => $ticked,
                ])
                ->log($ticked ? 'Boat trip ticked' : 'Boat trip unticked');
        });
    }
}

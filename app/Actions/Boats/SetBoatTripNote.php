<?php

namespace App\Actions\Boats;

use App\Models\UnitBoatTrip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sets the note of a trip from the Note field of the Boat Trips board. A blank note clears it. It keeps the tick, the
 * boat and the time. A note that does not change writes and logs nothing. See docs/24-boats/05-boats-board.md#layout.
 */
class SetBoatTripNote
{
    public function __invoke(User $actor, UnitBoatTrip $trip, ?string $note): void
    {
        $note = filled($note) ? trim((string) $note) : null;

        DB::transaction(function () use ($actor, $trip, $note): void {
            $locked = UnitBoatTrip::query()->with('reservationUnit.reservation')->lockForUpdate()->findOrFail($trip->id);

            if ($locked->note === $note) {
                return;
            }

            $locked->forceFill(['note' => $note])->save();

            activity()
                ->performedOn($locked->reservationUnit->reservation)
                ->causedBy($actor)
                ->event('reservation.boat_trip_note_set')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->reservation_unit_id,
                    'direction' => $locked->direction->value,
                    'note' => $note,
                ])
                ->log('Boat trip note set');
        });
    }
}

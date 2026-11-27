<?php

namespace App\Services\DoorLocks;

use App\Models\ReservationUnit;

/**
 * The name a stay's PIN or card carries on the lock: "{booking code} - {booker}". TTLock shows it in its app and returns it in the
 * access log, which splits it at " - " into booking code and guest when the PIN or card is not in our database.
 */
class StayLockLabel
{
    public static function for(ReservationUnit $unit): string
    {
        $unit->loadMissing('reservation.booker');

        return mb_substr(trim($unit->reservation->short_code.' - '.$unit->reservation->booker?->full_name, ' -'), 0, 60);
    }
}

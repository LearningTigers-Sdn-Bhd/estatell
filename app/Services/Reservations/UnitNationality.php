<?php

namespace App\Services\Reservations;

use App\Models\ReservationUnit;

/**
 * The nationality that decides a foreign-only tax for a unit: its primary guest, or the booker when the unit has no
 * primary guest. Null when that person has none. It writes nothing.
 * See docs/18-front-desk/01-check-in-and-check-out.md#taxes-and-deposit.
 */
class UnitNationality
{
    public function for(ReservationUnit $unit): ?string
    {
        $primary = $unit->guests()->where('is_primary', true)->with('guest')->first();

        return ($primary === null ? $unit->reservation->booker : $primary->guest)->nationality_code;
    }
}

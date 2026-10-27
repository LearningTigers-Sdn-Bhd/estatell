<?php

namespace App\Services\Reservations;

use App\Enums\SellUnit;
use App\Models\ReservationUnit;

class UnitCapacity
{
    /**
     * How many guests a unit holds: one for a bed unit, the booked adults and children for a room unit. `AttachGuest`
     * and `MoveGuestToUnit` refuse a guest past it. Expects `roomType` to be loaded or loads it.
     * See docs/12-reservations/05-booking-form.md#stays-in-and-capacity.
     */
    public function of(ReservationUnit $unit): int
    {
        return $unit->roomType->sell_unit === SellUnit::Bed ? 1 : max(1, $unit->adults + $unit->children);
    }
}

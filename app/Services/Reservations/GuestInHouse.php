<?php

namespace App\Services\Reservations;

use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;

/**
 * Tells whether a guest is in house: an occupant of a unit with status CheckedIn. A booker who does not stay is not.
 */
class GuestInHouse
{
    public function isInHouse(Guest $guest): bool
    {
        return ReservationUnit::query()
            ->where('property_id', $guest->property_id)
            ->where('status', UnitStatus::CheckedIn)
            ->whereIn('id', UnitGuest::query()->select('reservation_unit_id')->where('guest_id', $guest->id))
            ->exists();
    }
}

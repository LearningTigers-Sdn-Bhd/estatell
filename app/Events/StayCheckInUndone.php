<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A unit's check-in was taken back. Room QR sessions of the unit are revoked, as they are on check-out.
 */
class StayCheckInUndone
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId, public readonly int $reservationUnitId) {}
}

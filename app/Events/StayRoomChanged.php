<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A unit moved to another room. Door locks listen to move the PIN to the new door.
 */
class StayRoomChanged
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId, public readonly int $reservationUnitId) {}
}

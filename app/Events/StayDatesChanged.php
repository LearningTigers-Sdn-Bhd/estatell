<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A unit's departure date or time changed. Door locks listen to give a longer stay a PIN that lasts to the new departure.
 */
class StayDatesChanged
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId, public readonly int $reservationUnitId) {}
}

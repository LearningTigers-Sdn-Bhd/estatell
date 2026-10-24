<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A unit checked in. Door locks listen to give the stay a PIN.
 */
class StayCheckedIn
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId, public readonly int $reservationUnitId) {}
}

<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A unit left the checked-in state: checked out, or its check-in was undone. Door locks listen to take the PIN away.
 */
class StayCheckedOut
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId, public readonly int $reservationUnitId) {}
}

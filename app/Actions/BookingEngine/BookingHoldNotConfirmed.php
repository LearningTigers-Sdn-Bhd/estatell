<?php

namespace App\Actions\BookingEngine;

use RuntimeException;

/**
 * Rolls back `ConfirmBookingHold` when a step refuses. It never leaves the Action.
 */
class BookingHoldNotConfirmed extends RuntimeException
{
    public function __construct(public readonly string $refusal)
    {
        parent::__construct($refusal);
    }
}

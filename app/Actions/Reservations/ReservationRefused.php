<?php

namespace App\Actions\Reservations;

use RuntimeException;

/**
 * Raised inside `CreateReservation`'s transaction when a line is refused, so everything written so far rolls back.
 * It never leaves the Action.
 */
class ReservationRefused extends RuntimeException
{
    public function __construct(public readonly ReservationResult $result)
    {
        parent::__construct((string) $result->refusal);
    }
}

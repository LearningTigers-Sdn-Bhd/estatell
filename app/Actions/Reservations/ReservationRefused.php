<?php

namespace App\Actions\Reservations;

use RuntimeException;

/**
 * Raised inside the transaction of `CreateReservation` or `AddUnit` when a line or add-on is refused, so everything written so far rolls back.
 * It never leaves the Action.
 */
class ReservationRefused extends RuntimeException
{
    public function __construct(public readonly ReservationResult $result)
    {
        parent::__construct((string) $result->refusal);
    }
}

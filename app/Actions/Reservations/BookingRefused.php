<?php

namespace App\Actions\Reservations;

use RuntimeException;

/**
 * Raised inside the transaction of `CreateBooking` when a step is refused, so the guests, the reservation and the
 * reference number written so far roll back. It never leaves the Action.
 */
class BookingRefused extends RuntimeException
{
    public function __construct(public readonly BookingResult $result)
    {
        parent::__construct((string) $result->refusal);
    }
}

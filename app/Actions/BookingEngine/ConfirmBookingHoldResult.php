<?php

namespace App\Actions\BookingEngine;

use App\Models\Reservation;

/**
 * What `ConfirmBookingHold` returns: the reservation, or the code of the step that refused. A refusal rolls everything back.
 */
class ConfirmBookingHoldResult
{
    public const string REFUSAL_NOT_PAID = 'hold_not_paid';

    public const string REFUSAL_NO_SOURCE = 'booking_source_missing';

    public const string REFUSAL_NO_METHOD = 'online_method_missing';

    private function __construct(public readonly ?Reservation $reservation, public readonly ?string $refusal) {}

    public static function confirmed(Reservation $reservation): self
    {
        return new self($reservation, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }
}

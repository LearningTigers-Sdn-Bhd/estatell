<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A quote link became a booking. The AI assistant listens to mark the turn that sent it as Booked.
 */
class ConciergeQuoteBooked
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId, public readonly int $reservationId, public readonly ?int $aiTurnId) {}
}

<?php

namespace App\Actions\Concierge;

use RuntimeException;

/**
 * Thrown inside the transaction that books a Concierge walk-in, to roll the whole booking back when a later step says no. It never
 * leaves `ConfirmConciergeWalkIn`; the caller gets the refusal in `ConciergeWalkInBooked`.
 */
final class WalkInRefused extends RuntimeException
{
    public function __construct(public readonly string $refusal)
    {
        parent::__construct($refusal);
    }
}

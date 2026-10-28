<?php

namespace App\Actions\FrontDesk;

use RuntimeException;

/**
 * Raised inside the transaction of `CheckInWalkIn` when a walk-in that is saved as already checked out has a unit that
 * is refused at its check-in, its payment or its check-out, so the whole booking rolls back with it.
 * It never leaves the Action.
 */
class WalkInUnitRefused extends RuntimeException
{
    public function __construct(public readonly WalkInUnitResult $unit)
    {
        parent::__construct('A unit of the walk-in was refused.');
    }
}

<?php

namespace App\Actions\Boats;

use RuntimeException;

/**
 * Thrown inside a boat trip transaction when the fare refuses, so the trip write and the fare rows roll back. The Action
 * catches it and returns the refusal. It never leaves the Action.
 */
class BoatTripRefused extends RuntimeException
{
    public function __construct(public readonly string $refusal)
    {
        parent::__construct($refusal);
    }
}

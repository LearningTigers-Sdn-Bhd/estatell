<?php

namespace App\Actions\Reservations;

use RuntimeException;

/**
 * Raised inside the transaction of `ImportArrivals` to roll back everything written so far. It never leaves the Action.
 */
class ArrivalImportRefused extends RuntimeException
{
    public function __construct(public readonly ArrivalImportResult $result)
    {
        parent::__construct((string) $result->refusal);
    }
}

<?php

namespace App\Actions\FrontDesk;

use RuntimeException;

/**
 * Raised inside the transaction of `CheckInAndCollect` when a Collect now payment is refused, so the check-in and any
 * payment already taken roll back with it.
 * It never leaves the Action.
 */
class CollectNowRefused extends RuntimeException
{
    public function __construct(public readonly CheckInAndCollectResult $result)
    {
        parent::__construct((string) $result->paymentRefusal);
    }
}

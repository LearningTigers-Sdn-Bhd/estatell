<?php

namespace App\Actions\FrontDesk;

use RuntimeException;

/**
 * Raised inside the transaction of `CheckInUnit` when an add-on cannot post, so the status change rolls back with it.
 * It never leaves the Action.
 */
class UnitStayRefused extends RuntimeException
{
    public function __construct(public readonly UnitStayResult $result)
    {
        parent::__construct((string) $result->refusal);
    }
}

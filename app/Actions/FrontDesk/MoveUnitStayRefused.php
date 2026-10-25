<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\AssignmentResult;
use RuntimeException;

/**
 * Raised inside the transaction of `MoveUnitStay` when the date change or the move is refused, so the part that was
 * already written rolls back with it. It never leaves the Action.
 */
class MoveUnitStayRefused extends RuntimeException
{
    public function __construct(public readonly AssignmentResult $result)
    {
        parent::__construct((string) $result->refusal);
    }
}

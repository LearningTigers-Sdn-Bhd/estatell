<?php

namespace App\Actions\FrontDesk;

use RuntimeException;

/**
 * Raised inside the transaction of `CheckInWalkIn` when the server total is not the total the form showed, so the
 * booking rolls back with it.
 * It never leaves the Action.
 */
class WalkInAmountChanged extends RuntimeException {}

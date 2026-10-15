<?php

namespace App\Actions\Onboarding;

use RuntimeException;

/**
 * Thrown inside the rooms transaction to roll it back when a room number is already in use.
 */
class RoomNumbersTaken extends RuntimeException
{
    /**
     * @param  list<string>  $numbers
     */
    public function __construct(public array $numbers)
    {
        parent::__construct('Room numbers are already in use.');
    }
}

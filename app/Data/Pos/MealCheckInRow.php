<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * One room on the meal check-in list. `status` is `pending`, `partial` or `redeemed`. `redemptionIds` are the active
 * check-ins, newest first, so the screen can undo the last one.
 */
class MealCheckInRow extends Data
{
    /**
     * @param  list<int>  $redemptionIds
     */
    public function __construct(
        public int $reservationUnitId,
        public string $roomNumber,
        public string $guestName,
        public string $reservationReference,
        public int $entitledGuests,
        public int $redeemedGuests,
        public int $remainingGuests,
        public string $status,
        public array $redemptionIds,
    ) {}
}

<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The totals of the choices on the page: what is collected, refunded and moved to the Master, the rooms checked out
 * and settled, and the Master balance after the move.
 */
class GroupCheckOutSummaryData extends Data
{
    public function __construct(
        public string $collect,
        public string $refund,
        public string $moveToMaster,
        public int $roomsOut,
        public int $roomsSettled,
        public bool $settleMaster,
        public ?string $masterAfter,
        public ?string $onePayment,
    ) {}
}

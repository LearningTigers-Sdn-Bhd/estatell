<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The room or bed picked for one unit of a new booking, by the same unit index as `NewBookingGuestData`. A Room line
 * uses `roomId` and a Bed line uses `bedId`.
 */
class UnitPickData extends Data
{
    public function __construct(
        public int $unitIndex,
        public ?int $roomId = null,
        public ?int $bedId = null,
    ) {}
}

<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * A dorm whose every active bed is free on every night of a stay, so it can be booked as a whole dorm.
 * `bedIds` are its active beds in label order. See docs/12-reservations/03-bed-bookings.md.
 */
class WholeRoomData extends Data
{
    /**
     * @param  list<int>  $bedIds
     */
    public function __construct(
        public int $id,
        public string $label,
        public array $bedIds,
    ) {}
}

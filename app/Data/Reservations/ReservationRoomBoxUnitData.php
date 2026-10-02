<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One unit in the room box tooltip: its room type and the room or bed it has, or null when it has none.
 */
class ReservationRoomBoxUnitData extends Data
{
    public function __construct(
        public string $roomType,
        public ?string $assigned,
    ) {}
}

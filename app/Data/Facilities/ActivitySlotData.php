<?php

namespace App\Data\Facilities;

use Spatie\LaravelData\Data;

/**
 * One row of the Activities run sheet: a facility on the date at one time, with the places its Tentative and Confirmed
 * bookings take, and its guide and boat. `startsAt` and `endsAt` are `H:i`. `boatSeats` is the picked boat's seats, so
 * the page can warn when more people are booked than the boat holds.
 */
class ActivitySlotData extends Data
{
    public function __construct(
        public int $facilityId,
        public string $facility,
        public string $startsAt,
        public string $endsAt,
        public int $booked,
        public int $bookings,
        public int $capacity,
        public string $unit,
        public bool $isSession,
        public ?int $guideId,
        public ?int $boatId,
        public ?int $boatSeats,
    ) {}
}

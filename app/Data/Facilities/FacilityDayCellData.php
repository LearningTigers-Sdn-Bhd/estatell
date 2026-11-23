<?php

namespace App\Data\Facilities;

use Spatie\LaravelData\Data;

/**
 * One facility on one date of the Timeline View, sent only for dates with a live booking. `booked` is the most
 * Confirmed bookings take at one time that day, and `full` is true when it reaches the facility's How many, the same
 * rule as the clash check. `bookings` and `tentative` count the live and the Tentative bookings. `label` names the
 * booking (the event, or the guest and reference) when there is only one.
 */
class FacilityDayCellData extends Data
{
    public function __construct(
        public int $facilityId,
        public string $date,
        public int $booked,
        public bool $full,
        public int $bookings,
        public int $tentative,
        public ?string $label,
    ) {}
}

<?php

namespace App\Data\Facilities;

use Spatie\LaravelData\Data;

/** A session of a facility on one date, with the places left, for the Session select. Times are `HH:MM`. */
class FacilitySessionPlacesData extends Data
{
    public function __construct(
        public string $startsAt,
        public string $endsAt,
        public int $left,
    ) {}
}

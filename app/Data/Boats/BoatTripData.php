<?php

namespace App\Data\Boats;

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use Spatie\LaravelData\Data;

/**
 * One direction of a unit's boat trip as staff set it. A scheduled trip names its departure; a charter or own boat
 * trip names its time (`H:i`) and an optional note. `SetBoatTrip` checks it against the unit and the departure.
 */
class BoatTripData extends Data
{
    public function __construct(
        public BoatDirection $direction,
        public BoatMode $mode,
        public ?int $boatDepartureId = null,
        public ?string $time = null,
        public ?string $note = null,
    ) {}
}

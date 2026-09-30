<?php

namespace App\Data\Rates;

use App\Services\Availability\NightAvailability;
use Spatie\LaravelData\Data;

/** The units of one room type on one date in the rate calendar. `units` leaves out blocked units, so it is what can be sold. */
class RateCalendarAvailabilityData extends Data
{
    public function __construct(
        public string $date,
        public int $units,
        public int $sold,
        public int $open,
    ) {}

    public static function fromNight(NightAvailability $night): self
    {
        return new self(
            date: $night->date->toDateString(),
            units: max(0, $night->total - $night->blocked),
            sold: $night->booked,
            open: $night->available,
        );
    }
}

<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * One row of Today's Booking List. `created` is the local time `H:i` the booking was made.
 */
class TodayBookingRowData extends Data
{
    public function __construct(
        public int $id,
        public string $guest,
        public string $reference,
        public string $created,
        public int $rooms,
        public ?string $arrival,
        public ?string $departure,
        public string $url,
    ) {}
}

<?php

namespace App\Data\Portal;

use App\Data\Reservations\BookingQuoteData;
use Spatie\LaravelData\Data;

/**
 * One room type offered on the rate plan of a line. `quote` is the staff quote for the line's rooms or beds, with the
 * auto-added items, or null when the room type cannot be priced. `left` is the room type's free rooms or beds on every
 * night, less what the lines above take. A blocked room type has the `reason` it cannot be booked and is shown, not
 * hidden.
 */
class PortalOfferData extends Data
{
    public function __construct(
        public int $roomTypeId,
        public ?BookingQuoteData $quote,
        public int $left,
        public ?string $reason,
    ) {}
}

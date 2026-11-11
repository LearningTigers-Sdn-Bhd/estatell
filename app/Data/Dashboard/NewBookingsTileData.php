<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * The Today's New Booking tile: bookings created on the business date.
 */
class NewBookingsTileData extends Data
{
    /**
     * @param  int  $bookings  Reservations created on the business date.
     * @param  int  $rooms  Units on those reservations.
     */
    public function __construct(public int $bookings, public int $rooms) {}
}

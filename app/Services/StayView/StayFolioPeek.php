<?php

namespace App\Services\StayView;

use App\Data\StayView\StayFolioData;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Reservations\ReservationDirectory;

/**
 * Reads the money figures of a reservation for the Stay View peek sheet: room total, paid, billed to a company and
 * balance. They come from the reservations list query, so the sheet and the list always agree. It never writes.
 */
class StayFolioPeek
{
    public function __construct(private ReservationDirectory $directory) {}

    public function for(Property $property, Reservation $reservation): StayFolioData
    {
        return StayFolioData::fromMoney($this->directory->money($property, $reservation));
    }
}

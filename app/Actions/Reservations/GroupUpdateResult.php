<?php

namespace App\Actions\Reservations;

use App\Models\Reservation;

/**
 * What `UpdateReservationGroup` returns: the reservation, and the units whose posted room lines stay on their own folio
 * because the new billing mode now routes room charges to the Master folio (see `SyncRoutingRules`).
 */
class GroupUpdateResult
{
    /**
     * @param  list<int>  $unitsWithPostedRoomLines
     */
    public function __construct(
        public readonly Reservation $reservation,
        public readonly array $unitsWithPostedRoomLines = [],
    ) {}
}

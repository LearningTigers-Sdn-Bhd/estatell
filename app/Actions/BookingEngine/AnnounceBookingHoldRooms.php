<?php

namespace App\Actions\BookingEngine;

use App\Events\InventoryChanged;
use App\Models\BookingHold;

/**
 * Tell the channels which rooms a hold took or gave back, because ARI reads availability.
 */
class AnnounceBookingHoldRooms
{
    public function __invoke(BookingHold $hold): void
    {
        $roomTypeIds = $hold->lines()->reorder()->distinct()->pluck('room_type_id');

        foreach ($roomTypeIds as $roomTypeId) {
            InventoryChanged::dispatch($hold->property_id, $hold->arrival_date->toDateString(), $hold->departure_date->subDay()->toDateString(), $roomTypeId);
        }
    }
}

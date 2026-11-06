<?php

namespace App\Services\Boats;

use App\Models\BoatTripAddOn;
use App\Models\ReservationUnit;

/**
 * The add-on rows a unit's boat trips added as their fare. Other modules leave these rows to `ResyncBoatFares`.
 */
class BoatFareAddOns
{
    /**
     * @return list<int>
     */
    public function idsFor(ReservationUnit $unit): array
    {
        return array_values(array_map(intval(...), BoatTripAddOn::query()
            ->whereHas('unitBoatTrip', fn ($trips) => $trips->where('reservation_unit_id', $unit->id))
            ->pluck('unit_add_on_id')
            ->all()));
    }
}

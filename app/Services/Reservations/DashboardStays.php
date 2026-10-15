<?php

namespace App\Services\Reservations;

use App\Models\Property;
use App\Models\ReservationUnit;
use Illuminate\Database\Eloquent\Collection;

class DashboardStays
{
    /**
     * Load display details for readiness units in a fixed number of queries.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, ReservationUnit>
     */
    public function forUnits(Property $property, array $unitIds): Collection
    {
        return ReservationUnit::query()->where('property_id', $property->id)->whereKey($unitIds)
            ->with(['roomType', 'dayUse'])->get()->keyBy('id');
    }
}

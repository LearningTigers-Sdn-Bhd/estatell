<?php

namespace App\Services\Boats;

use App\Enums\BoatDirection;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class MissingBoatArrivals
{
    /**
     * A manual trip counts as set too. Only the absence of an inbound trip needs attention.
     *
     * @return Collection<int, ReservationUnit>
     */
    public function forDate(Property $property, CarbonImmutable $date): Collection
    {
        return ReservationUnit::query()->where('property_id', $property->id)
            ->where('status', UnitStatus::Confirmed)
            ->whereBetween('arrival_date', [$date->toDateString(), $date->addDay()->toDateString()])
            ->whereDoesntHave('boatTrips', fn ($trips) => $trips->where('direction', BoatDirection::In))
            ->with(['reservation.booker', 'roomType'])->orderBy('arrival_date')->orderBy('arrival_time')->orderBy('id')->get();
    }
}

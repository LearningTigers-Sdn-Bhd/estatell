<?php

namespace App\Services\Boats;

use App\Models\BoatDeparture;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads which unit trips use a departure. Reads only; the Actions decide what to do with the answers.
 */
class BoatTripUsage
{
    /** Whether any trip of any status uses the departure. */
    public function isUsed(BoatDeparture $boatDeparture): bool
    {
        return UnitBoatTrip::query()->where('boat_departure_id', $boatDeparture->id)->exists();
    }

    /**
     * The units with an upcoming trip on the departure: a unit status the trip direction accepts, and a stay date (the
     * arrival for Boat in, the departure for Boat out) on or after the business date. Oldest unit first. Pass `$lock`
     * inside a transaction to hold the unit rows.
     *
     * @return Collection<int, ReservationUnit>
     */
    public function upcomingUnits(BoatDeparture $boatDeparture, CarbonImmutable $businessDate, bool $lock = false): Collection
    {
        $query = ReservationUnit::query()
            ->select(['id', 'reservation_id'])
            ->whereIn('id', UnitBoatTrip::query()
                ->select('reservation_unit_id')
                ->where('boat_departure_id', $boatDeparture->id)
                ->where('direction', $boatDeparture->direction->value))
            ->whereIn('status', array_map(fn ($status): string => $status->value, $boatDeparture->direction->unitStatuses()))
            ->whereDate($boatDeparture->direction->tripDateColumn(), '>=', $businessDate->toDateString())
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** How many distinct reservations the upcoming units belong to. Used for "5 upcoming bookings". */
    public function upcomingBookingCount(BoatDeparture $boatDeparture, CarbonImmutable $businessDate): int
    {
        return $this->upcomingUnits($boatDeparture, $businessDate)->pluck('reservation_id')->unique()->count();
    }
}

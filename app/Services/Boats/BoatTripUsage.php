<?php

namespace App\Services\Boats;

use App\Enums\BoatDirection;
use App\Enums\UnitStatus;
use App\Models\BoatDeparture;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads which unit trips use a departure. Reads only; the Actions decide what to do with the answers.
 */
class BoatTripUsage
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
    ) {}

    /** The business date upcoming trips are judged against: the open business date, or today at the property. */
    public function businessDate(Property $property): CarbonImmutable
    {
        return $this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property);
    }

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
     * @return EloquentCollection<int, ReservationUnit>
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

    /**
     * Upcoming bookings by departure id, for the whole property, in one grouped query per direction. Same definition as
     * `upcomingUnits()`: a trip on the departure, a unit status the direction accepts, and a stay date on or after the
     * business date. Counts distinct reservations.
     *
     * @return array<int, int>
     */
    public function upcomingBookingCounts(Property $property, CarbonImmutable $businessDate): array
    {
        $counts = [];

        foreach (BoatDirection::cases() as $direction) {
            $rows = DB::table('unit_boat_trips')
                ->join('reservation_units', 'reservation_units.id', '=', 'unit_boat_trips.reservation_unit_id')
                ->where('unit_boat_trips.property_id', $property->id)
                ->where('unit_boat_trips.direction', $direction->value)
                ->whereNotNull('unit_boat_trips.boat_departure_id')
                ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, $direction->unitStatuses()))
                ->whereDate("reservation_units.{$direction->tripDateColumn()}", '>=', $businessDate->toDateString())
                ->groupBy('unit_boat_trips.boat_departure_id')
                ->selectRaw('unit_boat_trips.boat_departure_id, count(distinct reservation_units.reservation_id) as bookings')
                ->pluck('bookings', 'boat_departure_id');

            foreach ($rows as $departureId => $bookings) {
                $counts[(int) $departureId] = (int) $bookings;
            }
        }

        return $counts;
    }
}

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
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
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
                ->where('direction', $boatDeparture->direction->value)
                ->whereNotExists($this->sailingWithOwnTime($boatDeparture)))
            ->whereIn('status', array_map(fn ($status): string => $status->value, $boatDeparture->direction->unitStatuses()))
            ->whereDate($boatDeparture->direction->tripDateColumn(), '>=', $businessDate->toDateString())
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * The trips whose sailing has a time of its own keep it when the timetable changes, so they are not "upcoming" for a
     * time move. A trip is on the sailing it was moved to, else on the main sailing (the lowest id) of its departure and
     * date. See docs/24-boats/04-sailings.md#times.
     *
     * @return Closure(Builder): void
     */
    private function sailingWithOwnTime(BoatDeparture $boatDeparture): Closure
    {
        $timeColumn = $boatDeparture->direction === BoatDirection::In ? 'arrives_at' : 'leaves_at';
        $dateColumn = $boatDeparture->direction->tripDateColumn();

        return function ($query) use ($timeColumn, $dateColumn): void {
            $query->selectRaw('1')
                ->from('boat_sailings as own_time')
                ->whereColumn('own_time.boat_departure_id', 'unit_boat_trips.boat_departure_id')
                ->whereNotNull("own_time.{$timeColumn}")
                ->whereRaw("own_time.date = (select unit_date.{$dateColumn} from reservation_units as unit_date where unit_date.id = unit_boat_trips.reservation_unit_id)")
                ->where(function ($on) {
                    $on->whereColumn('own_time.id', 'unit_boat_trips.boat_sailing_id')
                        ->orWhere(function ($main) {
                            $main->whereNull('unit_boat_trips.boat_sailing_id')
                                ->whereRaw('own_time.id = (select min(first_sailing.id) from boat_sailings as first_sailing where first_sailing.boat_departure_id = own_time.boat_departure_id and first_sailing.date = own_time.date)');
                        });
                });
        };
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

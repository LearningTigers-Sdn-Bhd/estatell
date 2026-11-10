<?php

namespace App\Services\Boats;

use App\Enums\SailingKind;
use App\Enums\UnitStatus;
use App\Models\Boat;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The sailings of departures on one date. A departure with no stored sailing runs as the timetable: one main sailing
 * with the default boat and crew, built in memory, so a normal day needs no rows. Reads only. See
 * docs/24-boats/04-sailings.md#main-sailing.
 */
class DateSailings
{
    /**
     * The sailings of the departures on the date, by departure id, the main sailing first. Cancelled sailings are left
     * out unless asked for. Only guest sailings of a departure are here.
     *
     * @param  list<int>|null  $departureIds  Null for every departure of the property.
     * @return array<int, list<DateSailing>>
     */
    public function forDate(Property $property, CarbonInterface $date, ?array $departureIds = null, bool $withCancelled = false): array
    {
        $day = CarbonImmutable::parse($date->toDateString());

        $departures = BoatDeparture::query()
            ->where('property_id', $property->id)
            ->when($departureIds !== null, fn (Builder $query) => $query->whereKey($departureIds))
            ->with(['defaultBoat', 'crew'])
            ->orderBy('id')
            ->get();

        $stored = $departures->isEmpty() ? collect() : BoatSailing::query()
            ->where('property_id', $property->id)
            ->whereDate('date', $day->toDateString())
            ->where('kind', SailingKind::Guests->value)
            ->whereIn('boat_departure_id', $departures->modelKeys())
            ->with(['boat', 'crew'])
            ->orderBy('id')
            ->get()
            ->groupBy('boat_departure_id');

        $sailings = [];

        foreach ($departures as $departure) {
            $rows = $stored->get($departure->id);

            if ($rows === null || $rows->isEmpty()) {
                $sailings[$departure->id] = [$this->inMemory($departure, $day)];

                continue;
            }

            $list = [];

            foreach ($rows->values() as $index => $row) {
                if ($row->cancelled_at === null || $withCancelled) {
                    $list[] = $this->stored($departure, $row, $index === 0);
                }
            }

            $sailings[$departure->id] = $list;
        }

        return $sailings;
    }

    /**
     * The departures among `$departureIds` that are cancelled on the date.
     *
     * @param  list<int>  $departureIds
     * @return list<int>
     */
    public function cancelledDepartureIds(array $departureIds, CarbonInterface $date): array
    {
        if ($departureIds === []) {
            return [];
        }

        return BoatSailing::query()
            ->whereIn('boat_departure_id', $departureIds)
            ->whereDate('date', $date->toDateString())
            ->where('kind', SailingKind::Guests->value)
            ->whereNotNull('cancelled_at')
            ->distinct()
            ->pluck('boat_departure_id')
            ->map(intval(...))
            ->values()
            ->all();
    }

    /**
     * The trips on a sailing: its own (`boat_sailing_id`), and on the main sailing also the trips of the departure and
     * date with no sailing. Units that hold inventory only.
     *
     * @return Builder<UnitBoatTrip>
     */
    public function tripsOn(DateSailing $sailing): Builder
    {
        $departure = $sailing->departure;

        if ($departure === null) {
            return UnitBoatTrip::query()
                ->where('unit_boat_trips.property_id', $sailing->sailing?->property_id)
                ->where('unit_boat_trips.direction', $sailing->direction->value)
                ->where('unit_boat_trips.boat_sailing_id', $sailing->sailing?->id)
                ->whereIn('unit_boat_trips.reservation_unit_id', ReservationUnit::query()
                    ->select('id')
                    ->whereDate($sailing->direction->tripDateColumn(), $sailing->date->toDateString())
                    ->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory())));
        }

        return UnitBoatTrip::query()
            ->where('unit_boat_trips.property_id', $departure->property_id)
            ->where('unit_boat_trips.direction', $departure->direction->value)
            ->where('unit_boat_trips.boat_departure_id', $departure->id)
            ->whereIn('unit_boat_trips.reservation_unit_id', ReservationUnit::query()
                ->select('id')
                ->where('property_id', $departure->property_id)
                ->whereDate($departure->direction->tripDateColumn(), $sailing->date->toDateString())
                ->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory())))
            ->where(function (Builder $query) use ($sailing): void {
                $query->where('unit_boat_trips.boat_sailing_id', $sailing->sailing?->id);

                if ($sailing->isMain) {
                    $query->orWhereNull('unit_boat_trips.boat_sailing_id');
                }
            });
    }

    /**
     * The extra trips of the property on the date, by time. An extra trip is a guest sailing with no departure; trips are
     * moved onto it from the board. Cancelled sailings are never extra trips (an empty one is deleted), so none are left out.
     *
     * @return list<DateSailing>
     */
    public function extras(Property $property, CarbonInterface $date): array
    {
        return BoatSailing::query()
            ->where('property_id', $property->id)
            ->whereDate('date', $date->toDateString())
            ->where('kind', SailingKind::Guests->value)
            ->whereNull('boat_departure_id')
            ->with(['boat', 'crew'])
            ->orderBy('leaves_at')
            ->orderBy('id')
            ->get()
            ->map(fn (BoatSailing $sailing): DateSailing => new DateSailing(
                departure: null,
                direction: $sailing->direction,
                pickupPoint: $sailing->pickup_point,
                date: $sailing->date,
                sailing: $sailing,
                isMain: false,
                boat: $sailing->boat,
                crew: $sailing->crew,
                leavesAt: substr((string) $sailing->leaves_at, 0, 5),
                arrivesAt: substr((string) ($sailing->arrives_at ?? $sailing->leaves_at), 0, 5),
                timeChanged: false,
                seats: $sailing->boat?->seats,
            ))
            ->values()
            ->all();
    }

    /**
     * The boat jobs of the property on the date, by time, with their boat and crew. See docs/24-boats/04-sailings.md#jobs.
     *
     * @return Collection<int, BoatSailing>
     */
    public function jobs(Property $property, CarbonInterface $date): Collection
    {
        return BoatSailing::query()
            ->where('property_id', $property->id)
            ->whereDate('date', $date->toDateString())
            ->where('kind', SailingKind::Job->value)
            ->with(['boat', 'crew'])
            ->orderBy('leaves_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The sailing a trip is on: the one it was moved to, else the main sailing of its departure.
     *
     * @param  array<int, list<DateSailing>>  $sailingsByDeparture  From `forDate()`, for the trip's date.
     * @param  list<DateSailing>  $extras  From `extras()`, for the trip's date.
     */
    public function sailingOf(UnitBoatTrip $trip, array $sailingsByDeparture, array $extras = []): ?DateSailing
    {
        if ($trip->boat_sailing_id !== null) {
            foreach ($extras as $extra) {
                if ($extra->sailing?->id === $trip->boat_sailing_id) {
                    return $extra;
                }
            }
        }

        $list = $sailingsByDeparture[$trip->boat_departure_id] ?? [];

        if ($trip->boat_sailing_id !== null) {
            foreach ($list as $sailing) {
                if ($sailing->sailing?->id === $trip->boat_sailing_id) {
                    return $sailing;
                }
            }
        }

        return $list[0] ?? null;
    }

    private function inMemory(BoatDeparture $departure, CarbonImmutable $date): DateSailing
    {
        return new DateSailing(
            departure: $departure,
            direction: $departure->direction,
            pickupPoint: $departure->pickup_point,
            date: $date,
            sailing: null,
            isMain: true,
            boat: $departure->defaultBoat,
            crew: $departure->crew,
            leavesAt: substr($departure->leaves_at, 0, 5),
            arrivesAt: substr($departure->arrives_at, 0, 5),
            timeChanged: false,
            seats: $this->limit($departure, $departure->defaultBoat, true),
        );
    }

    private function stored(BoatDeparture $departure, BoatSailing $sailing, bool $isMain): DateSailing
    {
        $leavesAt = $sailing->leaves_at === null ? substr($departure->leaves_at, 0, 5) : substr($sailing->leaves_at, 0, 5);
        $arrivesAt = $sailing->arrives_at === null ? substr($departure->arrives_at, 0, 5) : substr($sailing->arrives_at, 0, 5);

        return new DateSailing(
            departure: $departure,
            direction: $departure->direction,
            pickupPoint: $departure->pickup_point,
            date: $sailing->date,
            sailing: $sailing,
            isMain: $isMain,
            boat: $sailing->boat,
            crew: $sailing->crew,
            leavesAt: $leavesAt,
            arrivesAt: $arrivesAt,
            timeChanged: $leavesAt !== substr($departure->leaves_at, 0, 5) || $arrivesAt !== substr($departure->arrives_at, 0, 5),
            seats: $this->limit($departure, $sailing->boat, $isMain),
            cancelled: $sailing->cancelled_at !== null,
        );
    }

    /**
     * The seat limit of one sailing: the boat's seats, else the departure's, else none. The main sailing with no boat
     * takes the departure's. Another sailing with no boat has none.
     */
    private function limit(BoatDeparture $departure, ?Boat $boat, bool $isMain): ?int
    {
        if ($boat === null) {
            return $isMain ? $departure->seats : null;
        }

        return $boat->seats ?? $departure->seats;
    }

    /**
     * The limit of a departure on a date: the sum of its sailings' limits, or null (no limit) when any sailing has none.
     *
     * @param  list<DateSailing>  $sailings
     */
    public function totalLimit(array $sailings): ?int
    {
        $limits = Collection::make($sailings)->map(fn (DateSailing $sailing): ?int => $sailing->seats);

        return $limits->isEmpty() || $limits->contains(null) ? null : (int) $limits->sum();
    }
}

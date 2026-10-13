<?php

namespace App\Services\Reports;

use App\Data\Reports\BoatManifestRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportSection;
use App\Data\Reports\ReportTotals;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\Tone;
use App\Enums\UnitStatus;
use App\Models\BoatDeparture;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Services\Reservations\ReservationRoomBox;
use Illuminate\Support\Collection;

/**
 * The boat trips of one date and direction, for the boat crew. A trip counts when its unit holds inventory (Confirmed,
 * Checked in or Checked out) and its stay date is the date: the arrival for Boat in, the departure for Boat out. Rows
 * follow the departures in time order, with the charter and own boat trips last. Subtotals use the same seats as
 * `BoatSeats`.
 */
class BoatManifestReport implements HasSections, HasTotals, Report
{
    public function __construct(
        private UnitReportRows $unitRows,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys  Trip ids.
     * @return list<BoatManifestRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $direction = $filters->direction ?? BoatDirection::In;
        $trips = $this->trips($property, $filters->from, $direction);

        if ($rowKeys !== null) {
            $trips = $trips->only(array_map(intval(...), $rowKeys));
        }

        $tripByUnit = $trips->keyBy('reservation_unit_id');
        $positions = [];

        $rows = $this->unitRows->rows(
            ReservationUnit::query()
                ->where('property_id', $property->id)
                ->whereIn('id', $tripByUnit->keys()->all())
                ->orderBy('id'),
            null,
            fn (array $unitIds): array => $direction === BoatDirection::In
                ? $this->roomBox->assignmentsForUnits($unitIds)
                : $this->roomBox->lastNightAssignmentsForUnits($unitIds),
            function (ReservationUnit $unit, ?string $room) use ($tripByUnit, $direction, &$positions): BoatManifestRow {
                $trip = $tripByUnit[$unit->id];
                $time = $direction === BoatDirection::In ? $unit->arrival_time : $unit->departure_time;
                $positions[$trip->id] = $this->position($trip, $time);

                return BoatManifestRow::fromModel($trip, $unit, $room);
            },
        );

        usort($rows, fn (BoatManifestRow $first, BoatManifestRow $second): int => $positions[$first->key] <=> $positions[$second->key]);

        return $rows;
    }

    /**
     * One section for each departure, in the order of the rows, then one for the charter and own boat trips.
     *
     * @param  list<BoatManifestRow>  $rows
     * @return list<ReportSection>
     */
    public function sections(array $rows, ReportFilters $filters): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row->boat_departure_id === null ? 'front-desk' : $row->boat_departure_id][] = $row;
        }

        return array_values(array_map(function (array $group): ReportSection {
            $first = $group[0];
            $keys = array_map(fn (BoatManifestRow $row): int => $row->key, $group);

            if ($first->boat_departure_id === null) {
                return new ReportSection(
                    title: __('Charter and own boat (front desk only)'),
                    rowKeys: $keys,
                    printedByDefault: false,
                );
            }

            $taken = array_sum(array_map(fn (BoatManifestRow $row): int => $row->total, $group));
            $limit = $first->seats;

            return new ReportSection(
                title: $first->departure,
                rowKeys: $keys,
                subtotal: $limit === null
                    ? (string) $taken
                    : __(':taken / :limit seats', ['taken' => $taken, 'limit' => $limit]),
                tone: $limit !== null && $taken > $limit ? Tone::Warning : null,
            );
        }, $groups));
    }

    /**
     * The adults, children and total guests of the rows.
     *
     * @param  list<BoatManifestRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'departure' => null,
            'unit' => null,
            'lead_guest' => null,
            'phone' => null,
            'adults' => array_sum(array_map(fn (BoatManifestRow $row): int => $row->adults, $rows)),
            'children' => array_sum(array_map(fn (BoatManifestRow $row): int => $row->children, $rows)),
            'total' => array_sum(array_map(fn (BoatManifestRow $row): int => $row->total, $rows)),
            'reservation' => null,
            'note' => null,
        ]);
    }

    /**
     * Whether the property has any departure, so the page can say "No departures yet" instead of "No boat in trips".
     */
    public function hasDepartures(Property $property): bool
    {
        return BoatDeparture::query()->where('property_id', $property->id)->exists();
    }

    /**
     * The trips of the date in one direction, by trip id, with their departure loaded.
     *
     * @return Collection<int, UnitBoatTrip>
     */
    private function trips(Property $property, string $date, BoatDirection $direction): Collection
    {
        return UnitBoatTrip::query()
            ->with('boatDeparture')
            ->where('property_id', $property->id)
            ->where('direction', $direction->value)
            ->whereIn('reservation_unit_id', ReservationUnit::query()
                ->select('id')
                ->where($direction->tripDateColumn(), $date)
                ->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory())))
            ->get()
            ->keyBy('id');
    }

    /**
     * The sort key of a trip: scheduled trips by departure time and id, then charter and own trips by their time.
     *
     * @return list<int|string>
     */
    private function position(UnitBoatTrip $trip, string $unitTime): array
    {
        if ($trip->mode === BoatMode::Scheduled && $trip->boatDeparture !== null) {
            return [0, $trip->boatDeparture->leaves_at, $trip->boatDeparture->id, $trip->id];
        }

        return [1, $unitTime, 0, $trip->id];
    }
}

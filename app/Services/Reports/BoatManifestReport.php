<?php

namespace App\Services\Reports;

use App\Data\Reports\BoatManifestRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportSection;
use App\Data\Reports\ReportTotals;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\StayType;
use App\Enums\Tone;
use App\Enums\UnitStatus;
use App\Models\BoatDeparture;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Services\Boats\DateSailing;
use App\Services\Boats\DateSailings;
use App\Services\Boats\SailingLabels;
use App\Services\Reservations\ReservationRoomBox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The boat trips of one date and direction (or both), for the boat crew. A trip counts when its unit holds inventory
 * (Confirmed, Checked in or Checked out) and its stay date is the date: the arrival for Boat in, the departure for Boat
 * out. Rows follow the sailings in time order, then the charter and own boat trips, then the units that arrive or
 * leave with no trip set (Boat not set), so the desk can follow up. Subtotals use the same seats as `BoatSeats`.
 */
class BoatManifestReport implements HasPrintView, HasSections, HasTotals, Report
{
    public function __construct(
        private UnitReportRows $unitRows,
        private ReservationRoomBox $roomBox,
        private DateSailings $dateSailings,
        private SailingLabels $labels,
    ) {}

    /**
     * The rows of one direction, or of both with `bothDirections`, in time order: scheduled departures, then charter
     * and own boat trips, then the units with no trip ("Boat not set").
     *
     * @param  list<int|string>|null  $rowKeys  Trip ids, and negative keys for units with no trip.
     * @return list<BoatManifestRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $directions = $filters->bothDirections ? [BoatDirection::Out, BoatDirection::In] : [$filters->direction ?? BoatDirection::In];
        $keys = $rowKeys === null ? null : array_map(intval(...), $rowKeys);
        $rows = [];
        $positions = [];
        $sailings = $this->dateSailings->forDate($property, CarbonImmutable::parse($filters->from), withCancelled: true);
        $extras = $this->dateSailings->extras($property, CarbonImmutable::parse($filters->from));

        foreach ($directions as $direction) {
            $trips = $this->trips($property, $filters->from, $direction);

            if ($keys !== null) {
                $trips = $trips->only($keys);
            }

            $tripByUnit = $trips->keyBy('reservation_unit_id');
            $rooms = fn (array $unitIds): array => $direction === BoatDirection::In
                ? $this->roomBox->assignmentsForUnits(array_values(array_map(intval(...), $unitIds)))
                : $this->roomBox->lastNightAssignmentsForUnits(array_values(array_map(intval(...), $unitIds)));

            $tripRows = $tripByUnit->isEmpty() ? [] : $this->unitRows->rows(
                ReservationUnit::query()
                    ->where('property_id', $property->id)
                    ->whereIn('id', $tripByUnit->keys()->all())
                    ->orderBy('id'),
                null,
                $rooms,
                function (ReservationUnit $unit, ?string $room) use ($tripByUnit, $direction, $sailings, $extras, &$positions): BoatManifestRow {
                    $trip = $tripByUnit[$unit->id];
                    $time = $direction === BoatDirection::In ? $unit->arrival_time : $unit->departure_time;
                    $sailing = $trip->mode === BoatMode::Scheduled ? $this->dateSailings->sailingOf($trip, $sailings, $extras) : null;
                    $positions[$trip->id] = $this->position($trip, $time, $sailing);

                    return BoatManifestRow::fromModel($trip, $unit, $room, $sailing === null ? null : $this->sailingDetails($sailing));
                },
            );

            $missing = $this->unitRows->rows(
                ReservationUnit::query()
                    ->where('property_id', $property->id)
                    ->where($direction->tripDateColumn(), $filters->from)
                    ->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, $direction->unitStatuses()))
                    ->where('stay_type', StayType::Overnight->value)
                    ->whereDoesntHave('boatTrips', fn ($trips) => $trips->where('direction', $direction->value))
                    ->when($keys !== null, fn ($query) => $query->whereIn('id', array_map(
                        fn (int $key): int => intdiv(-$key - ($direction === BoatDirection::Out ? 1 : 0), 2),
                        array_values(array_filter($keys, fn (int $key): bool => $key < 0 && (-$key % 2 === 1) === ($direction === BoatDirection::Out))),
                    )))
                    ->orderBy('id'),
                null,
                $rooms,
                function (ReservationUnit $unit, ?string $room) use ($direction, &$positions): BoatManifestRow {
                    $row = BoatManifestRow::notSet($unit, $direction, $room);
                    $positions[$row->key] = [2, $direction->value, $direction === BoatDirection::In ? $unit->arrival_time : $unit->departure_time, $unit->id];

                    return $row;
                },
            );

            array_push($rows, ...$tripRows, ...$missing);
        }

        usort($rows, fn (BoatManifestRow $first, BoatManifestRow $second): int => $positions[$first->key] <=> $positions[$second->key]);

        return $rows;
    }

    /**
     * One section for each sailing, in the order of the rows, then one for the charter and own boat trips.
     *
     * @param  list<BoatManifestRow>  $rows
     * @return list<ReportSection>
     */
    public function sections(array $rows, ReportFilters $filters): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[match (true) {
                $row->not_set => 'not-set',
                $row->sailing_key === null => 'front-desk',
                default => $row->sailing_key,
            }][] = $row;
        }

        $prefix = fn (BoatManifestRow $row): string => $filters->bothDirections
            ? BoatDirection::from($row->direction)->label().' · '
            : '';

        return array_values(array_map(function (array $group) use ($prefix): ReportSection {
            $first = $group[0];
            $keys = array_map(fn (BoatManifestRow $row): int => $row->key, $group);
            $guests = array_sum(array_map(fn (BoatManifestRow $row): int => $row->total, $group));

            if ($first->not_set) {
                return new ReportSection(
                    title: __('Boat not set: follow up'),
                    rowKeys: $keys,
                    subtotal: trans_choice(':count guest|:count guests', $guests),
                    tone: Tone::Warning,
                );
            }

            if ($first->sailing_key === null) {
                return new ReportSection(
                    title: __('Charter and own boat'),
                    rowKeys: $keys,
                    subtotal: trans_choice(':count guest|:count guests', $guests),
                );
            }

            $limit = $first->seats;

            return new ReportSection(
                title: $prefix($first).$first->sailing_title,
                rowKeys: $keys,
                subtotal: $limit === null
                    ? (string) $guests
                    : __(':taken / :limit seats', ['taken' => $guests, 'limit' => $limit]),
                tone: $limit !== null && $guests > $limit ? Tone::Warning : null,
            );
        }, $groups));
    }

    /**
     * What the rows and the PDF need to name a sailing: its key, its title and times from `SailingLabels`, boat, crew and
     * seat limit.
     *
     * @return array{key: string, title: string, times: string, boat: ?string, crew: ?string, seats: ?int}
     */
    private function sailingDetails(DateSailing $sailing): array
    {
        return [
            'key' => $sailing->key(),
            'title' => $this->labels->title($sailing),
            'times' => $this->labels->times($sailing),
            'boat' => $this->labels->boat($sailing),
            'crew' => $this->labels->crew($sailing),
            'seats' => $sailing->seats,
        ];
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
            'agent' => null,
            'phone' => null,
            'adults' => array_sum(array_map(fn (BoatManifestRow $row): int => $row->adults, $rows)),
            'children' => array_sum(array_map(fn (BoatManifestRow $row): int => $row->children, $rows)),
            'total' => array_sum(array_map(fn (BoatManifestRow $row): int => $row->total, $rows)),
            'boat' => null,
            'crew' => null,
            'tick' => null,
            'reservation' => null,
            'note' => null,
        ]);
    }

    public function printView(): string
    {
        return 'pdf.boat-manifest';
    }

    public function printOrientation(): string
    {
        return 'landscape';
    }

    /**
     * The sheet the boat crew work from: one band per sailing in time order, with an empty band for a sailing that
     * carries nobody, then own boat and charter, then the rooms with no boat set.
     *
     * @param  list<BoatManifestRow>  $rows
     * @return array<string, mixed>
     */
    public function printData(Property $property, ReportFilters $filters, array $rows): array
    {
        $directions = $filters->bothDirections ? [BoatDirection::Out, BoatDirection::In] : [$filters->direction ?? BoatDirection::In];
        $sailings = collect($this->dateSailings->forDate($property, CarbonImmutable::parse($filters->from)))
            ->flatten()
            ->merge($this->dateSailings->extras($property, CarbonImmutable::parse($filters->from)))
            ->filter(fn (DateSailing $sailing): bool => in_array($sailing->direction, $directions, true))
            ->sortBy(fn (DateSailing $sailing): array => [$sailing->leavesAt, $sailing->sortId()])
            ->values();
        $departures = BoatDeparture::query()
            ->where('property_id', $property->id)
            ->whereIn('direction', array_map(fn (BoatDirection $direction): string => $direction->value, $directions))
            ->orderBy('leaves_at')
            ->orderBy('id')
            ->get();
        $rowsByGroup = [];

        foreach ($rows as $row) {
            $rowsByGroup[match (true) {
                $row->not_set => 'not-set',
                $row->sailing_key !== null => $row->sailing_key,
                default => $row->direction.'-'.$row->mode,
            }][] = $row;
        }

        $sections = [];

        foreach ($sailings as $sailing) {
            $group = $rowsByGroup[$sailing->key()] ?? [];

            if ($group !== [] || $sailing->departure === null || $sailing->departure->active) {
                $sections[] = $this->printSection($sailing->direction->value, str_replace(':', '', $sailing->leavesAt), $sailing->pickupPoint, $group, $this->labels->boat($sailing), $sailing->crew->map(fn ($member): array => ['name' => $member->name, 'in_charge' => (bool) $member->pivot->in_charge])->values()->all());
            }
        }

        foreach ($directions as $direction) {
            foreach ([BoatMode::Own, BoatMode::Charter] as $mode) {
                $group = $rowsByGroup[$direction->value.'-'.$mode->value] ?? [];

                if ($group !== []) {
                    $jetty = $departures->firstWhere('direction', $direction)?->pickup_point;
                    $sections[] = $this->printSection($direction->value, mb_strtoupper($mode->label()), $jetty, $group, $mode->label(), []);
                }
            }
        }

        if (isset($rowsByGroup['not-set'])) {
            $sections[] = $this->printSection('not_set', __('Follow up'), null, $rowsByGroup['not-set'], null, []);
        }

        return [
            'sheetTitle' => mb_strtoupper(__(':property manifest', ['property' => $property->name])),
            'sheetDate' => CarbonImmutable::parse($filters->from)->format('j M Y'),
            'sections' => $sections,
        ];
    }

    /**
     * @param  list<BoatManifestRow>  $rows
     * @param  list<array{name: string, in_charge: bool}>  $crew  The person in charge first.
     * @return array{band: string, kind: string, heading: string, jetty: ?string, boat: ?string, crew: list<array{name: string, in_charge: bool}>, rows: list<BoatManifestRow>, pax: int}
     */
    private function printSection(string $kind, string $heading, ?string $jetty, array $rows, ?string $boat, array $crew): array
    {
        return [
            'band' => BoatDirection::tryFrom($kind)?->label() ?? __('Boat not set'),
            'kind' => $kind,
            'heading' => $heading,
            'jetty' => $jetty,
            'boat' => $boat,
            'crew' => $crew,
            'rows' => $rows,
            'pax' => array_sum(array_map(fn (BoatManifestRow $row): int => $row->total, $rows)),
        ];
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
     * The sort key of a trip: scheduled trips by sailing time and sailing id, then charter and own trips by their time.
     *
     * @return list<int|string>
     */
    private function position(UnitBoatTrip $trip, string $unitTime, ?DateSailing $sailing): array
    {
        if ($sailing !== null) {
            return [0, $sailing->leavesAt, $sailing->sortId(), $trip->id];
        }

        return [1, $unitTime, 0, $trip->id];
    }
}

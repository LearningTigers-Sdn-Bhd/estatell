<?php

namespace App\Services\Reports;

use App\Data\Reports\InHouseRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRoomBox;
use Illuminate\Database\Eloquent\Builder;

/**
 * The reservation units that stay on one date: arrival on or before the date and departure after it, so the arrival
 * day counts and the departure day does not. A day use arrives and leaves on the same
 * date, so it is in-house on that date. Only Checked in and Checked out units count, so an expected or missed
 * arrival is not in-house. An early check-out moves the departure date to the day it happens and removes the unused
 * nights, so the dates alone give the right answer for any date, past or open. `unit_nights` is read only for the
 * room or bed of that night. The total row sums adults and children.
 */
class InHouseReport implements HasTotals, Report
{
    public function __construct(
        private UnitReportRows $unitRows,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * The units that stay on the date (`from`), without order or relations. The Guest list counts them with it.
     *
     * @return Builder<ReservationUnit>
     */
    public function query(Property $property, ReportFilters $filters): Builder
    {
        $date = $filters->from;

        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('arrival_date', '<=', $date)
            ->where(fn ($stay) => $stay
                ->where('departure_date', '>', $date)
                ->orWhere(fn ($dayUse) => $dayUse
                    ->where('stay_type', StayType::DayUse->value)
                    ->where('arrival_date', $date)))
            ->whereIn('status', [UnitStatus::CheckedIn, UnitStatus::CheckedOut]);
    }

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<InHouseRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        return $this->unitRows->rows(
            $this->query($property, $filters)->orderBy('id'),
            $rowKeys,
            fn (array $unitIds): array => $this->roomBox->assignmentsForUnitsOn($unitIds, $filters->from),
            fn (ReservationUnit $unit, ?string $room, string $balance): InHouseRow => InHouseRow::fromModel($unit, $room, $balance),
        );
    }

    /**
     * The sums of adults and children.
     *
     * @param  list<InHouseRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'reservation' => null,
            'guest' => null,
            'room' => null,
            'arrival' => null,
            'arrival_time' => null,
            'departure' => null,
            'departure_time' => null,
            'guests' => null,
            'adults' => array_sum(array_map(fn (InHouseRow $row): int => $row->adults, $rows)),
            'children' => array_sum(array_map(fn (InHouseRow $row): int => $row->children, $rows)),
            'company' => null,
            'balance' => null,
        ]);
    }
}

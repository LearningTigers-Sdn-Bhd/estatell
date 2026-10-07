<?php

namespace App\Services\Reports;

use App\Data\Reports\ArrivalRow;
use App\Data\Reports\ReportFilters;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRoomBox;

/**
 * The reservation units that arrive in the date range, by arrival date. Units that hold inventory count: Confirmed
 * (still to arrive), Checked in and Checked out. Cancelled and no-show units are left out because nobody arrives.
 */
class ArrivalsReport implements Report
{
    public function __construct(
        private UnitReportRows $unitRows,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<ArrivalRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        return $this->unitRows->rows(
            ReservationUnit::query()
                ->where('property_id', $property->id)
                ->whereBetween('arrival_date', [$filters->from, $filters->to])
                ->whereIn('status', UnitStatus::holdingInventory())
                ->orderBy('arrival_date')
                ->orderBy('id'),
            $rowKeys,
            fn (array $unitIds): array => $this->roomBox->assignmentsForUnits($unitIds),
            fn (ReservationUnit $unit, ?string $room, string $balance): ArrivalRow => ArrivalRow::fromModel($unit, $room, $balance),
        );
    }
}

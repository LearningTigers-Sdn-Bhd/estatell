<?php

namespace App\Services\Reports;

use App\Data\Reports\DepartureRow;
use App\Data\Reports\ReportFilters;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRoomBox;

/**
 * The reservation units that depart in the date range, by departure date. The same units count as in Arrivals: the
 * ones that hold inventory. Cancelled and no-show units are left out because nobody departs.
 */
class DeparturesReport implements Report
{
    public function __construct(
        private UnitReportRows $unitRows,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<DepartureRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        return $this->unitRows->rows(
            ReservationUnit::query()
                ->where('property_id', $property->id)
                ->whereBetween('departure_date', [$filters->from, $filters->to])
                ->whereIn('status', UnitStatus::holdingInventory())
                ->orderBy('departure_date')
                ->orderBy('id'),
            $rowKeys,
            fn (array $unitIds): array => $this->roomBox->lastNightAssignmentsForUnits($unitIds),
            fn (ReservationUnit $unit, ?string $room, string $balance): DepartureRow => DepartureRow::fromModel($unit, $room, $balance),
        );
    }
}

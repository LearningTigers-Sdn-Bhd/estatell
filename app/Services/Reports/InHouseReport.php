<?php

namespace App\Services\Reports;

use App\Data\Reports\InHouseRow;
use App\Data\Reports\ReportFilters;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRoomBox;

/**
 * The reservation units that stay on one date: arrival on or before the date and departure after it, so the arrival
 * day counts and the departure day does not. Only Checked in and Checked out units count, so an expected or missed
 * arrival is not in-house. An early check-out moves the departure date to the day it happens and removes the unused
 * nights, so the dates alone give the right answer for any date, past or open. `unit_nights` is read only for the
 * room or bed of that night.
 */
class InHouseReport implements Report
{
    public function __construct(
        private UnitReportRows $unitRows,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<InHouseRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $date = $filters->from;

        return $this->unitRows->rows(
            ReservationUnit::query()
                ->where('property_id', $property->id)
                ->where('arrival_date', '<=', $date)
                ->where('departure_date', '>', $date)
                ->whereIn('status', [UnitStatus::CheckedIn, UnitStatus::CheckedOut])
                ->orderBy('id'),
            $rowKeys,
            fn (array $unitIds): array => $this->roomBox->assignmentsForUnitsOn($unitIds, $date),
            fn (ReservationUnit $unit, ?string $room, string $balance): InHouseRow => InHouseRow::fromModel($unit, $room, $balance),
        );
    }
}

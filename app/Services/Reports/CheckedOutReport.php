<?php

namespace App\Services\Reports;

use App\Data\Reports\CheckedOutRow;
use App\Data\Reports\ReportFilters;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRoomBox;

/**
 * The units that checked out in the date range, by departure date, with their balance. An early check-out moves the
 * departure date to the day the guest left, so the departure date is the check-out day. A check-out done after the
 * audit keeps its departure date. See docs/22-reports/02-guest-list-and-daily-report.md#2-checked-out.
 */
class CheckedOutReport implements Report
{
    public function __construct(
        private UnitReportRows $unitRows,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<CheckedOutRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $timeZone = $property->loadMissing('settings')->settings->time_zone ?? config('app.timezone');

        return $this->unitRows->rows(
            ReservationUnit::query()
                ->where('property_id', $property->id)
                ->whereBetween('departure_date', [$filters->from, $filters->to])
                ->where('status', UnitStatus::CheckedOut)
                ->orderBy('departure_date')
                ->orderBy('id'),
            $rowKeys,
            fn (array $unitIds): array => $this->roomBox->lastNightAssignmentsForUnits($unitIds),
            fn (ReservationUnit $unit, ?string $room, string $balance): CheckedOutRow => CheckedOutRow::fromModel($unit, $room, $balance, $timeZone),
        );
    }
}

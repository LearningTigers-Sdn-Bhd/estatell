<?php

namespace App\Services\Reports;

use App\Data\Reports\CheckedOutRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRoomBox;
use Illuminate\Database\Eloquent\Builder;

/**
 * The units that checked out in the date range, by departure date, with their balance. An early check-out moves the
 * departure date to the day the guest left, so the departure date is the check-out day. A check-out done after the
 * audit keeps its departure date. See docs/22-reports/02-guest-list-and-daily-report.md#2-checked-out.
 */
class CheckedOutReport implements HasTotals, Report
{
    public function __construct(
        private UnitReportRows $unitRows,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * The units that checked out in the range, without order or relations. The Guest list counts them with it.
     *
     * @return Builder<ReservationUnit>
     */
    public function query(Property $property, ReportFilters $filters): Builder
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereBetween('departure_date', [$filters->from, $filters->to])
            ->where('status', UnitStatus::CheckedOut);
    }

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<CheckedOutRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $timeZone = $property->loadMissing('settings')->settings->time_zone ?? config('app.timezone');

        return $this->unitRows->rows(
            $this->query($property, $filters)
                ->orderBy('departure_date')
                ->orderBy('id'),
            $rowKeys,
            fn (array $unitIds): array => $this->roomBox->lastNightAssignmentsForUnits($unitIds),
            fn (ReservationUnit $unit, ?string $room, string $balance): CheckedOutRow => CheckedOutRow::fromModel($unit, $room, $balance, $timeZone),
        );
    }

    /**
     * The sums of nights, adults and children, and the balance of each reservation once.
     *
     * @param  list<CheckedOutRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'reservation' => null,
            'guest' => null,
            'room' => null,
            'checked_out_at' => null,
            'phone' => null,
            'departure' => null,
            'unit' => null,
            'room_type' => null,
            'guests' => null,
            'company' => null,
            ...$this->unitRows->totalFigures($rows),
        ]);
    }
}

<?php

namespace App\Services\Reports;

use App\Data\Reports\ArrivalRow;
use App\Data\Reports\ReportFilters;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioBalance;
use App\Services\Reservations\ReservationRoomBox;

/**
 * The reservation units that arrive in the date range, by arrival date. Units that hold inventory count: Confirmed
 * (still to arrive), Checked in and Checked out. Cancelled and no-show units are left out because nobody arrives.
 */
class ArrivalsReport implements Report
{
    public function __construct(
        private FolioBalance $folioBalance,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<ArrivalRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereBetween('arrival_date', [$filters->from, $filters->to])
            ->whereIn('status', UnitStatus::holdingInventory())
            ->when($rowKeys !== null, fn ($query) => $query->whereIn('id', $rowKeys))
            ->with(['reservation.booker', 'reservation.company', 'roomType', 'guests.guest'])
            ->orderBy('arrival_date')
            ->orderBy('id')
            ->get();

        $rooms = $this->roomBox->assignmentsForUnits($units->pluck('id')->all());
        $balances = $this->folioBalance->forReservations($units->pluck('reservation_id')->unique()->values()->all());

        return $units
            ->map(fn (ReservationUnit $unit): ArrivalRow => ArrivalRow::fromModel($unit, $rooms[$unit->id] ?? null, $balances[$unit->reservation_id]))
            ->all();
    }
}

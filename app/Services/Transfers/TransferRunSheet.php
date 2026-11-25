<?php

namespace App\Services\Transfers;

use App\Data\Transfers\TransferRunRowData;
use App\Enums\TransferDirection;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\UnitTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The run sheet of one date: every transfer in of units arriving that day and every transfer out of units leaving it,
 * for units that are Confirmed, CheckedIn or CheckedOut, sorted by pick-up time.
 * See docs/35-transfers/01-land-transfers.md#run-sheet.
 */
class TransferRunSheet
{
    /**
     * @return list<TransferRunRowData>
     */
    public function rows(Property $property, CarbonImmutable $date, bool $canViewGuest): array
    {
        $statuses = [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value];

        return array_values(UnitTransfer::query()
            ->where('property_id', $property->id)
            ->whereHas('reservationUnit', fn (Builder $unit) => $unit->whereIn('status', $statuses))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $in) => $in
                    ->where('direction', TransferDirection::In->value)
                    ->whereHas('reservationUnit', fn (Builder $unit) => $unit->whereDate('arrival_date', $date)))
                ->orWhere(fn (Builder $out) => $out
                    ->where('direction', TransferDirection::Out->value)
                    ->whereHas('reservationUnit', fn (Builder $unit) => $unit->whereDate('departure_date', $date))))
            ->with(['transferRoute', 'reservationUnit.reservation.booker'])
            ->orderBy('pickup_time')
            ->orderBy('id')
            ->get()
            ->map(fn (UnitTransfer $transfer): TransferRunRowData => TransferRunRowData::fromModel($transfer, $canViewGuest))
            ->all());
    }
}

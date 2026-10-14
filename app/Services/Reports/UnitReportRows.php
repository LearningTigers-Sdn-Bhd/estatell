<?php

namespace App\Services\Reports;

use App\Models\ReservationUnit;
use App\Services\Folio\FolioBalance;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared part of the reports that list reservation units (Arrivals, Departures, In-house): it keeps the row keys
 * asked for, loads the guest, company and room type, and looks up the rooms and the balances with one query each.
 * The report builds the query (property, dates, status, order) and the row class.
 */
class UnitReportRows
{
    public function __construct(private FolioBalance $folioBalance) {}

    /**
     * @template TRow
     *
     * @param  Builder<ReservationUnit>  $query
     * @param  list<int|string>|null  $rowKeys
     * @param  Closure(list<int>): array<int, string>  $roomsFor  The room number or bed label by unit id, for the unit ids.
     * @param  Closure(ReservationUnit, ?string, string): TRow  $makeRow  Gets the unit, its room and the reservation balance.
     * @return list<TRow>
     */
    public function rows(Builder $query, ?array $rowKeys, Closure $roomsFor, Closure $makeRow): array
    {
        $units = $query
            ->when($rowKeys !== null, fn (Builder $query) => $query->whereIn('id', $rowKeys))
            ->with(['reservation.booker', 'reservation.company', 'roomType', 'guests.guest', 'dayUse'])
            ->get();

        $rooms = $roomsFor(array_values(array_map(intval(...), $units->pluck('id')->all())));
        $balances = $this->folioBalance->forReservations(array_values(array_map(intval(...), $units->pluck('reservation_id')->unique()->all())));

        return array_values($units
            ->map(fn (ReservationUnit $unit) => $makeRow($unit, $rooms[$unit->id] ?? null, $balances[$unit->reservation_id]))
            ->all());
    }
}

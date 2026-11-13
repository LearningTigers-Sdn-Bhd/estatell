<?php

namespace App\Services\Reports;

use App\Data\Reports\ArrivalRow;
use App\Data\Reports\CheckedOutRow;
use App\Data\Reports\DepartureRow;
use App\Data\Reports\InHouseRow;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioBalance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared part of the reports that list reservation units (Arrivals, Departures, In-house): it keeps the row keys
 * asked for, loads the guest, company and room type, and looks up the rooms and the balances with one query each.
 * The report builds the query (property, dates, status, order) and the row class. It also works out the shared figures
 * of their total rows.
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
            ->with(['reservation.booker', 'reservation.company', 'reservation.bookingSource', 'roomType', 'guests.guest', 'dayUse'])
            ->get();

        $rooms = $roomsFor(array_values(array_map(intval(...), $units->pluck('id')->all())));
        $balances = $this->folioBalance->forReservations(array_values(array_map(intval(...), $units->pluck('reservation_id')->unique()->all())));

        return array_values($units
            ->map(fn (ReservationUnit $unit) => $makeRow($unit, $rooms[$unit->id] ?? null, $balances[$unit->reservation_id]))
            ->all());
    }

    /**
     * The sums of nights, adults and children, and the balance of each reservation once.
     *
     * @param  list<ArrivalRow|DepartureRow|CheckedOutRow>  $rows
     * @return array{nights: int, adults: int, children: int, balance: string}
     */
    public function totalFigures(array $rows): array
    {
        return [
            'nights' => array_sum(array_map(fn (ArrivalRow|DepartureRow|CheckedOutRow $row): int => $row->nights, $rows)),
            'adults' => array_sum(array_map(fn (ArrivalRow|DepartureRow|CheckedOutRow $row): int => $row->adults, $rows)),
            'children' => array_sum(array_map(fn (ArrivalRow|DepartureRow|CheckedOutRow $row): int => $row->children, $rows)),
            'balance' => $this->balanceTotal($rows),
        ];
    }

    /**
     * The balance of each reservation in the rows, counted once. Every unit row shows the balance of its whole
     * reservation, so a reservation with three units would count three times if the rows were added.
     *
     * @param  list<ArrivalRow|DepartureRow|CheckedOutRow|InHouseRow>  $rows
     */
    public function balanceTotal(array $rows): string
    {
        $balances = [];

        foreach ($rows as $row) {
            $balances[$row->reservation] = $row->balance;
        }

        return (string) array_reduce($balances, fn (BigDecimal $sum, string $balance): BigDecimal => $sum->plus($balance), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
    }
}

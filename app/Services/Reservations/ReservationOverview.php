<?php

namespace App\Services\Reservations;

use App\Data\Reservations\ReservationData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Enums\FolioStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The header, summary figures and unit list of one reservation, in four queries (units with room types, the
 * night totals, the first-night rooms and the folio balance). Figures come from the units that hold inventory. Dates fall back to all
 * units when none does.
 */
class ReservationOverview
{
    public function __construct(private ReservationRoomBox $roomBox) {}

    /**
     * Expects `booker`, `creator` and `company` to be loaded.
     *
     * @return array{reservation: ReservationData, units: list<ReservationUnitOptionData>, models: Collection<int, ReservationUnit>}
     */
    public function build(Reservation $reservation, bool $canViewGuest): array
    {
        $units = $reservation->units()->with('roomType')->orderBy('id')->get();
        $assigned = $this->roomBox->assignmentsForUnits($units->pluck('id')->all());
        $nights = $this->nightTotals($units);
        $live = $units->filter(fn (ReservationUnit $unit): bool => $unit->status->holdsInventory());
        $dated = $live->isEmpty() ? $units : $live;

        $total = $live->reduce(fn (BigDecimal $sum, ReservationUnit $unit): BigDecimal => $sum->plus($nights[$unit->id]['total'] ?? '0'), BigDecimal::zero());

        return [
            'reservation' => ReservationData::fromModel(
                $reservation,
                unitCount: $live->isEmpty() ? $units->count() : $live->count(),
                firstArrival: $dated->min(fn (ReservationUnit $unit): string => $unit->arrival_date->toDateString()),
                lastDeparture: $dated->max(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString()),
                roomNights: (int) $live->sum(fn (ReservationUnit $unit): int => $nights[$unit->id]['count'] ?? 0),
                stayTotal: $total->toScale(2)->__toString(),
                balance: $this->balance($reservation),
                canViewGuest: $canViewGuest,
            ),
            'units' => $units->map(fn (ReservationUnit $unit): ReservationUnitOptionData => ReservationUnitOptionData::fromModel($unit, $assigned[$unit->id] ?? null))->values()->all(),
            'models' => $units,
        ];
    }

    /**
     * The sum of the balances of the folios that are not Voided, or null while the reservation has no folio.
     */
    private function balance(Reservation $reservation): ?string
    {
        $row = DB::table('folios')
            ->leftJoin('folio_lines', 'folio_lines.folio_id', '=', 'folios.id')
            ->where('folios.reservation_id', $reservation->id)
            ->where('folios.status', '!=', FolioStatus::Voided->value)
            ->selectRaw('count(distinct folios.id) as folio_count, COALESCE(SUM(folio_lines.amount), 0)::numeric(14, 2) as balance')
            ->first();

        return (int) $row->folio_count === 0 ? null : (string) $row->balance;
    }

    /**
     * @param  Collection<int, ReservationUnit>  $units
     * @return array<int, array{count: int, total: string}> By unit id
     */
    private function nightTotals(Collection $units): array
    {
        return DB::table('unit_nights')
            ->whereIn('reservation_unit_id', $units->pluck('id')->all())
            ->groupBy('reservation_unit_id')
            ->selectRaw('reservation_unit_id, count(*) as night_count, sum(amount) as night_total')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->reservation_unit_id => ['count' => (int) $row->night_count, 'total' => (string) $row->night_total]])
            ->all();
    }
}

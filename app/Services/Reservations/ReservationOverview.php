<?php

namespace App\Services\Reservations;

use App\Data\Reservations\CollectedTaxesData;
use App\Data\Reservations\ReservationData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Enums\CollectedTaxesPayer;
use App\Enums\FolioStatus;
use App\Enums\StayType;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Folio\CollectedTaxPayer;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The header, summary figures and unit list of one reservation, in five queries (six with a company: the taxes collected at check-in) (units with room types, the
 * night and day use totals, the first-night rooms, the primary guests and the folio balance). Figures come from the units that hold inventory. Dates fall back to all
 * units when none does.
 */
class ReservationOverview
{
    public function __construct(private ReservationRoomBox $roomBox, private StayLabel $stayLabel, private CollectedTaxPayer $collectedTaxPayer) {}

    /**
     * Expects `booker`, `creator`, `company` and `bookingSource` to be loaded.
     *
     * @return array{reservation: ReservationData, units: list<ReservationUnitOptionData>, models: EloquentCollection<int, ReservationUnit>}
     */
    public function build(Reservation $reservation, bool $canViewGuest): array
    {
        $units = $reservation->units()->with('roomType')->orderBy('id')->get();
        $assigned = $this->roomBox->assignmentsForUnits(array_values(array_map(intval(...), $units->pluck('id')->all())));
        $primaryGuests = $this->primaryGuests($units);
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
                stayType: $dated->first()->stay_type ?? StayType::Overnight,
                stayLabel: $this->stayLabel->forUnits($dated),
                stayTotal: $total->toScale(2)->__toString(),
                balance: $this->balance($reservation),
                canViewGuest: $canViewGuest,
                collectedTaxes: $this->collectedTaxes($reservation),
            ),
            'units' => array_values($units->map(fn (ReservationUnit $unit): ReservationUnitOptionData => ReservationUnitOptionData::fromModel($unit, $assigned[$unit->id] ?? null, $primaryGuests[$unit->id] ?? null))->all()),
            'models' => $units,
        ];
    }

    /**
     * Who pays the taxes collected at check-in, for a booking with a company on a property that has such taxes. One
     * query, only when the booking has a company.
     */
    private function collectedTaxes(Reservation $reservation): ?CollectedTaxesData
    {
        $company = $reservation->company;

        if ($company === null) {
            return null;
        }

        $taxes = $this->collectedTaxPayer->taxNames($reservation->property_id);

        if ($taxes === []) {
            return null;
        }

        $bookingPayer = $reservation->collected_taxes_payer;

        return new CollectedTaxesData(
            companyPays: $bookingPayer === null ? $company->pays_collected_taxes : $bookingPayer === CollectedTaxesPayer::Company,
            companyName: $company->name,
            taxes: $taxes,
            companyDefaultPays: $company->pays_collected_taxes,
            bookingPayer: $bookingPayer?->value,
        );
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
     * @param  EloquentCollection<int, ReservationUnit>  $units
     * @return array<int, string> The primary guest's name by unit id
     */
    private function primaryGuests(Collection $units): array
    {
        return DB::table('unit_guests')
            ->join('guests', 'guests.id', '=', 'unit_guests.guest_id')
            ->whereIn('unit_guests.reservation_unit_id', $units->pluck('id')->all())
            ->where('unit_guests.is_primary', true)
            ->pluck('guests.full_name', 'unit_guests.reservation_unit_id')
            ->all();
    }

    /**
     * @param  EloquentCollection<int, ReservationUnit>  $units
     * @return array<int, array{count: int, total: string}> By unit id
     */
    private function nightTotals(Collection $units): array
    {
        $dayUse = DB::table('unit_day_uses')
            ->whereIn('reservation_unit_id', $units->pluck('id')->all())
            ->selectRaw('reservation_unit_id, 0 as night_count, amount as night_total');

        return DB::table('unit_nights')
            ->whereIn('reservation_unit_id', $units->pluck('id')->all())
            ->groupBy('reservation_unit_id')
            ->selectRaw('reservation_unit_id, count(*) as night_count, sum(amount) as night_total')
            ->unionAll($dayUse)
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->reservation_unit_id => ['count' => (int) $row->night_count, 'total' => (string) $row->night_total]])
            ->all();
    }
}

<?php

namespace App\Services\Guests;

use App\Enums\StayType;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A guest's stays, nights, spend, last visit and source, calculated on each request and never stored.
 * A stay is a unit the guest occupies that is Checked in or Checked out. Spend is the Charge and Adjustment lines of
 * the stay (no tax, no payments, no Opening balance), credited to the unit's primary guest only. It only reads.
 * See docs/11-guests/01-guest-profiles.md#stay-history.
 */
class GuestStayHistory
{
    /**
     * The guest's stays, newest arrival first, with `is_primary` and `spend` (null when the stay has no spend) selected
     * and the relations the stay rows show loaded.
     *
     * @return Collection<int, ReservationUnit>
     */
    public function stays(Guest $guest): Collection
    {
        $units = ReservationUnit::query()
            ->join('unit_guests', 'unit_guests.reservation_unit_id', '=', 'reservation_units.id')
            ->where('unit_guests.guest_id', $guest->id)
            ->where('reservation_units.property_id', $guest->property_id)
            ->whereIn('reservation_units.status', self::stayStatuses())
            ->select('reservation_units.*', 'unit_guests.is_primary')
            ->orderByDesc('reservation_units.arrival_date')
            ->orderByDesc('reservation_units.id')
            ->with([
                'reservation.bookingSource',
                'reservation.company',
                'roomType',
                'dayUse.room',
                'nights' => fn ($nights) => $nights->orderBy('date')->with('room', 'bed.room'),
            ])
            ->get();

        if ($units->isEmpty()) {
            return $units;
        }

        $spend = $this->unitSpend($guest->property_id, array_values(array_unique($units->map(fn (ReservationUnit $unit): int => $unit->reservation_id)->all())))
            ->pluck('amount', 'reservation_unit_id');

        foreach ($units as $unit) {
            $unit->setAttribute('spend', $spend->has($unit->id) ? (string) $spend->get($unit->id) : null);
        }

        return $units;
    }

    /**
     * The spend of each stay, as rows of `reservation_unit_id` and `amount`, for the stays of the given reservations or
     * of the whole property. Each line counts once: a tax or discount child takes its parent's source, a unit night,
     * add-on or reservation unit source gives the unit, then the folio's unit, then the reservation's first stay.
     *
     * @param  list<int>|null  $reservationIds
     */
    public function unitSpend(int $propertyId, ?array $reservationIds = null): Builder
    {
        $sourceType = 'CASE WHEN folio_lines.parent_line_id IS NULL THEN folio_lines.source_type ELSE parent_lines.source_type END';
        $sourceId = 'CASE WHEN folio_lines.parent_line_id IS NULL THEN folio_lines.source_id ELSE parent_lines.source_id END';

        $lines = DB::table('folio_lines')
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->leftJoin('folio_lines as parent_lines', 'parent_lines.id', '=', 'folio_lines.parent_line_id')
            ->leftJoin('unit_nights as source_nights', fn ($join) => $join
                ->whereRaw("{$sourceType} = ?", [(new UnitNight)->getMorphClass()])
                ->whereRaw("source_nights.id = {$sourceId}"))
            ->leftJoin('unit_add_ons as source_add_ons', fn ($join) => $join
                ->whereRaw("{$sourceType} = ?", [(new UnitAddOn)->getMorphClass()])
                ->whereRaw("source_add_ons.id = {$sourceId}"))
            ->where('folios.property_id', $propertyId)
            ->when($reservationIds !== null, fn (Builder $query) => $query->whereIn('folios.reservation_id', $reservationIds))
            ->whereIn('folio_lines.kind', [TransactionKind::Charge->value, TransactionKind::Adjustment->value])
            ->whereNotIn('folio_lines.transaction_code_id', TransactionCode::query()
                ->where('property_id', $propertyId)
                ->where('system_key', SystemTransactionCode::OpeningBalance->value)
                ->select('id'))
            ->selectRaw(
                "COALESCE(CASE WHEN {$sourceType} = ? THEN {$sourceId} END, source_nights.reservation_unit_id, source_add_ons.reservation_unit_id, folios.reservation_unit_id, "
                .'(SELECT MIN(first_stays.id) FROM reservation_units AS first_stays WHERE first_stays.reservation_id = folios.reservation_id AND first_stays.status IN (?, ?))) AS reservation_unit_id, folio_lines.amount',
                [(new ReservationUnit)->getMorphClass(), ...self::stayStatuses()],
            );

        return DB::query()
            ->fromSub($lines, 'line_units')
            ->join('reservation_units as spend_units', 'spend_units.id', '=', 'line_units.reservation_unit_id')
            ->whereIn('spend_units.status', self::stayStatuses())
            ->groupBy('line_units.reservation_unit_id')
            ->select('line_units.reservation_unit_id')
            ->selectRaw('SUM(line_units.amount) AS amount');
    }

    /**
     * One row per guest of the property with stays: `guest_id`, `stays`, `stays_last_year`, `nights`, `day_uses`,
     * `spend`, `last_visit` (latest checked-out departure), `latest_departure` (any stay, for sorting) and `in_house`.
     * The guest list joins it to its page query.
     */
    public function figures(Property $property, string $since): Builder
    {
        $overnight = StayType::Overnight->value;
        $dayUse = StayType::DayUse->value;
        $checkedOut = UnitStatus::CheckedOut->value;
        $checkedIn = UnitStatus::CheckedIn->value;

        return DB::table('unit_guests')
            ->join('reservation_units as stay_units', 'stay_units.id', '=', 'unit_guests.reservation_unit_id')
            ->leftJoinSub($this->unitSpend($property->id), 'unit_spend', 'unit_spend.reservation_unit_id', '=', 'stay_units.id')
            ->where('stay_units.property_id', $property->id)
            ->whereIn('stay_units.status', self::stayStatuses())
            ->groupBy('unit_guests.guest_id')
            ->select('unit_guests.guest_id')
            ->selectRaw('COUNT(*) AS stays')
            ->selectRaw('COUNT(*) FILTER (WHERE stay_units.departure_date >= ?) AS stays_last_year', [$since])
            ->selectRaw("COALESCE(SUM(stay_units.departure_date - stay_units.arrival_date) FILTER (WHERE stay_units.stay_type = '{$overnight}'), 0) AS nights")
            ->selectRaw("COUNT(*) FILTER (WHERE stay_units.stay_type = '{$dayUse}') AS day_uses")
            ->selectRaw('ROUND(COALESCE(SUM(unit_spend.amount) FILTER (WHERE unit_guests.is_primary), 0), 2) AS spend')
            ->selectRaw("MAX(stay_units.departure_date) FILTER (WHERE stay_units.status = '{$checkedOut}') AS last_visit")
            ->selectRaw('MAX(stay_units.departure_date) AS latest_departure')
            ->selectRaw("BOOL_OR(stay_units.status = '{$checkedIn}') AS in_house");
    }

    /**
     * The total spend of the stays, from their `spend` attribute, counting only the stays where the guest is primary.
     *
     * @param  Collection<int, ReservationUnit>  $stays
     */
    public function totalSpend(Collection $stays): BigDecimal
    {
        return $stays->reduce(
            fn (BigDecimal $total, ReservationUnit $unit): BigDecimal => $unit->getAttribute('is_primary') && $unit->getAttribute('spend') !== null
                ? $total->plus((string) $unit->getAttribute('spend'))
                : $total,
            BigDecimal::zero(),
        )->toScale(2);
    }

    /**
     * The nights of an overnight stay: departure minus arrival, so an early check-out counts the nights stayed.
     */
    public function nights(ReservationUnit $unit): int
    {
        return $unit->stay_type === StayType::Overnight ? (int) $unit->arrival_date->diffInDays($unit->departure_date) : 0;
    }

    /**
     * @return list<string>
     */
    public static function stayStatuses(): array
    {
        return [UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value];
    }
}

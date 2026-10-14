<?php

namespace App\Services\Reports;

use App\Data\Reports\NonNationalGuestRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Models\Guest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Guests\CountryOptions;
use App\Services\Reservations\ReservationRoomBox;
use App\Services\Reservations\UnitNationality;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The reservation units with a foreign nationality that stayed in the date range, and the tourism tax posted for them
 * in the range. A unit is foreign when its nationality (its primary guest, else the booker) is set and is not `MY`,
 * the rule `TaxCalculator` uses for a foreign-only tax. The nationality shows as a country name. The note counts the
 * units in the range with no nationality at all, because their tourism tax may be missing.
 * See docs/22-reports/01-core-reports.md.
 */
class NonNationalGuestsReport implements HasNote, HasTotals, Report
{
    public function __construct(
        private StayUnits $stays,
        private UnitNationality $unitNationality,
        private GuestIdentities $identities,
        private TourismTaxByUnit $tourismTax,
        private ReservationRoomBox $roomBox,
        private CountryOptions $countries,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys  Reservation unit ids.
     * @return list<NonNationalGuestRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $units = $this->stays->between($property, $filters, $rowKeys === null ? null : array_map('intval', $rowKeys));
        $nationalities = $this->unitNationality->forUnits($units);
        $foreign = $units->filter(fn (ReservationUnit $unit): bool => UnitNationality::isForeign($nationalities[$unit->id], $property->country_code))->values();

        if ($foreign->isEmpty()) {
            return [];
        }

        $taxes = $this->tourismTax->forRange($property, $filters, array_values(array_map(intval(...), $foreign->pluck('reservation_id')->unique()->all())));
        $rooms = $this->roomBox->assignmentsForUnits(array_values(array_map(intval(...), $foreign->pluck('id')->all())));
        $guests = $foreign->mapWithKeys(fn (ReservationUnit $unit): array => [$unit->id => $this->guestOf($unit)]);
        $identities = $this->identities->partsForGuests(array_values(array_map(intval(...), $guests->pluck('id')->all())));

        return array_values($foreign
            ->map(fn (ReservationUnit $unit): NonNationalGuestRow => NonNationalGuestRow::fromUnit(
                $unit,
                $guests[$unit->id]->full_name,
                $this->countries->name($nationalities[$unit->id]) ?? '',
                GuestIdentities::text($identities[$guests[$unit->id]->id] ?? null),
                $identities[$guests[$unit->id]->id]['type'] ?? '',
                $identities[$guests[$unit->id]->id]['number'] ?? '',
                $rooms[$unit->id] ?? null,
                $this->stays->nightsInRange($unit, $filters),
                $taxes[$unit->id] ?? '0.00',
            ))
            ->all());
    }

    public function note(Property $property, ReportFilters $filters): ?string
    {
        $units = $this->stays->between($property, $filters);
        $missing = collect($this->unitNationality->forUnits($units))->filter(fn (?string $code): bool => blank($code))->count();

        return match (true) {
            $missing === 0 => null,
            $missing === 1 => __('1 unit has no nationality. Its tourism tax may be missing.'),
            default => __(':count units have no nationality. Their tourism tax may be missing.', ['count' => $missing]),
        };
    }

    /**
     * The sums of nights, adults, children and tourism tax.
     *
     * @param  list<NonNationalGuestRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'reservation' => null,
            'guest' => null,
            'nationality' => null,
            'identity' => null,
            'id_type' => null,
            'id_number' => null,
            'room' => null,
            'arrival' => null,
            'arrival_time' => null,
            'departure' => null,
            'departure_time' => null,
            'nights' => array_sum(array_map(fn (NonNationalGuestRow $row): int => $row->nights, $rows)),
            'guests' => null,
            'adults' => array_sum(array_map(fn (NonNationalGuestRow $row): int => $row->adults, $rows)),
            'children' => array_sum(array_map(fn (NonNationalGuestRow $row): int => $row->children, $rows)),
            'tourism_tax' => (string) array_reduce($rows, fn (BigDecimal $sum, NonNationalGuestRow $row): BigDecimal => $sum->plus($row->tourism_tax), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp),
        ]);
    }

    /**
     * The person whose nationality counts for the unit: its primary guest, else the booker.
     */
    private function guestOf(ReservationUnit $unit): Guest
    {
        return $unit->guests->firstWhere('is_primary', true)->guest ?? $unit->reservation->booker;
    }
}

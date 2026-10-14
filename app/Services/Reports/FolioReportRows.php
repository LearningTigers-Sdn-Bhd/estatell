<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Shared\BadgeData;
use App\Enums\FolioType;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;

/**
 * The shared part of the two folio balance reports: it reads the balances of one date with `FolioBalancesOnDate`,
 * loads the folios with their reservation, payer and units in a fixed number of queries, and works out the display
 * facts. The report picks the sign of the balance and maps the entries to its row class.
 *
 * Status is today's status of the unit (a unit folio) or of the reservation (a master or third party folio), not the
 * status on the date: the ledger is exact for a past date, the bookings are not kept by date.
 */
class FolioReportRows
{
    public function __construct(
        private FolioBalancesOnDate $balances,
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
    ) {}

    /**
     * @param  bool  $debit  True for the folios that owe on the date, false for the ones in credit.
     * @param  list<int|string>|null  $rowKeys  Folio ids.
     * @return list<FolioReportEntry> In folio id order
     */
    public function entries(Property $property, ReportFilters $filters, bool $debit, ?array $rowKeys): array
    {
        $date = $filters->from;
        $folioIds = $rowKeys === null ? null : array_map('intval', $rowKeys);
        $figures = $this->balances->forDate($property, $date, $debit, $folioIds);

        if ($figures === []) {
            return [];
        }

        $folios = Folio::query()
            ->where('property_id', $property->id)
            ->whereIn('id', array_keys($figures))
            ->with(['reservation.booker', 'reservation.units', 'payerGuest', 'payerCompany', 'reservationUnit'])
            ->orderBy('id')
            ->get();

        return array_values($folios->map(fn (Folio $folio): FolioReportEntry => $this->entry($folio, $figures[$folio->id], $date))->all());
    }

    /**
     * The note for a past or future date: the status columns show today's status. Null on the business date.
     */
    public function note(Property $property, ReportFilters $filters): ?string
    {
        $businessDate = ($this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property))->toDateString();

        return $filters->from === $businessDate ? null : __('Status as of today');
    }

    private function entry(Folio $folio, FolioBalanceOnDate $figures, string $date): FolioReportEntry
    {
        $reservation = $folio->reservation;
        [$arrival, $departure] = $this->dates($folio);

        return new FolioReportEntry(
            folioId: $folio->id,
            folio: $folio->number,
            reservation: $reservation->reference,
            payer: $folio->payerCompany->name ?? $folio->payerGuest->full_name ?? $reservation->booker->full_name,
            arrival: $arrival,
            departure: $departure,
            status: $folio->type === FolioType::Unit && $folio->reservationUnit !== null
                ? BadgeData::fromUnitStatus($folio->reservationUnit->status)
                : BadgeData::fromReservationStatus($reservation->status),
            figures: $figures,
            daysSinceDeparture: $departure !== null && $departure <= $date
                ? (int) CarbonImmutable::parse($departure)->diffInDays(CarbonImmutable::parse($date))
                : null,
        );
    }

    /**
     * The arrival and departure dates of the folio: the unit's for a unit folio, else the first arrival and last
     * departure of the reservation's units that were not cancelled or missed (every unit when none is left).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function dates(Folio $folio): array
    {
        $unit = $folio->type === FolioType::Unit ? $folio->reservationUnit : null;

        if ($unit !== null) {
            return [$unit->arrival_date->toDateString(), $unit->departure_date->toDateString()];
        }

        $units = $folio->reservation->units;
        $live = $units->reject(fn (ReservationUnit $unit): bool => in_array($unit->status, [UnitStatus::Cancelled, UnitStatus::NoShow], true));
        $units = $live->isEmpty() ? $units : $live;

        if ($units->isEmpty()) {
            return [null, null];
        }

        return [
            $units->min(fn (ReservationUnit $unit): string => $unit->arrival_date->toDateString()),
            $units->max(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString()),
        ];
    }
}

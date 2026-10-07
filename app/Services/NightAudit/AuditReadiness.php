<?php

namespace App\Services\NightAudit;

use App\Enums\AuditCheck;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What stands between a property and a clean night audit: the units the audit would otherwise settle by policy or
 * leave as exceptions. It reads only. Every check is scoped to the property and to its current business date. A
 * blocker is a check with `AuditCheck::isBlocker()`. See docs/20-night-audit/02-readiness-panel.md.
 */
class AuditReadiness
{
    public function __construct(private CurrentBusinessDate $currentBusinessDate) {}

    /**
     * Every item, blockers first. Empty while the property has no business date.
     *
     * @return list<ReadinessItem>
     */
    public function items(Property $property): array
    {
        $date = $this->currentBusinessDate->date($property);

        if ($date === null) {
            return [];
        }

        $items = [
            ...$this->inHouseWithoutRoom($property, $date),
            ...$this->arrivalsNotCheckedIn($property, $date),
            ...$this->departuresNotCheckedOut($property, $date),
            ...$this->foliosOpenAfterCheckOut($property),
            ...$this->carriedExceptions($property),
        ];

        usort($items, fn (ReadinessItem $a, ReadinessItem $b): int => [! $a->check->isBlocker()] <=> [! $b->check->isBlocker()]);

        return $items;
    }

    /**
     * @return list<ReadinessItem>
     */
    public function blockers(Property $property): array
    {
        return array_values(array_filter($this->items($property), fn (ReadinessItem $item): bool => $item->check->isBlocker()));
    }

    /**
     * @return list<ReadinessItem>
     */
    private function inHouseWithoutRoom(Property $property, CarbonImmutable $date): array
    {
        return $this->units($property, UnitStatus::CheckedIn)
            ->whereHas('nights', fn (Builder $nights) => $nights->whereDate('date', $date->toDateString())->whereNull('room_id')->whereNull('bed_id'))
            ->get()
            ->map(fn (ReservationUnit $unit): ReadinessItem => $this->item(AuditCheck::InHouseWithoutRoom, $unit))
            ->all();
    }

    /**
     * Units whose arrival is on or before the business date. A unit marked as a late arrival is expected, so it is not listed.
     *
     * @return list<ReadinessItem>
     */
    private function arrivalsNotCheckedIn(Property $property, CarbonImmutable $date): array
    {
        return $this->units($property, UnitStatus::Confirmed)
            ->whereDate('arrival_date', '<=', $date->toDateString())
            ->where('late_arrival_expected', false)
            ->get()
            ->map(fn (ReservationUnit $unit): ReadinessItem => $this->item(AuditCheck::ArrivalNotCheckedIn, $unit))
            ->all();
    }

    /**
     * @return list<ReadinessItem>
     */
    private function departuresNotCheckedOut(Property $property, CarbonImmutable $date): array
    {
        return $this->units($property, UnitStatus::CheckedIn)
            ->whereDate('departure_date', '<=', $date->toDateString())
            ->get()
            ->map(fn (ReservationUnit $unit): ReadinessItem => $this->item(AuditCheck::DepartureNotCheckedOut, $unit))
            ->all();
    }

    /**
     * @return list<ReadinessItem>
     */
    private function foliosOpenAfterCheckOut(Property $property): array
    {
        return Folio::query()
            ->where('property_id', $property->id)
            ->where('type', FolioType::Unit->value)
            ->where('status', FolioStatus::Open->value)
            ->whereHas('reservationUnit', fn (Builder $units) => $units->where('status', UnitStatus::CheckedOut->value))
            ->with(['reservationUnit.reservation.booker', 'reservationUnit.guests.guest'])
            ->orderBy('id')
            ->get()
            ->map(fn (Folio $folio): ReadinessItem => $this->item(AuditCheck::FolioOpenAfterCheckOut, $folio->reservationUnit, $folio->number))
            ->all();
    }

    /**
     * @return list<ReadinessItem>
     */
    private function carriedExceptions(Property $property): array
    {
        return NightAuditException::query()
            ->where('property_id', $property->id)
            ->open()
            ->whereNotNull('reservation_unit_id')
            ->with(['reservationUnit.reservation.booker', 'reservationUnit.guests.guest'])
            ->orderBy('id')
            ->get()
            ->map(fn (NightAuditException $exception): ReadinessItem => $this->item(AuditCheck::CarriedException, $exception->reservationUnit, $exception->type->label(), $exception->message))
            ->all();
    }

    /**
     * @return Builder<ReservationUnit>
     */
    private function units(Property $property, UnitStatus $status): Builder
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('status', $status->value)
            ->with(['reservation.booker', 'guests.guest'])
            ->orderBy('id');
    }

    private function item(AuditCheck $check, ReservationUnit $unit, ?string $detail = null, ?string $message = null): ReadinessItem
    {
        return new ReadinessItem(
            check: $check,
            reservationId: $unit->reservation_id,
            reservationUnitId: $unit->id,
            reference: $unit->reservation->reference,
            guest: $unit->guests->firstWhere('is_primary', true)?->guest->full_name ?? $unit->reservation->booker?->full_name,
            detail: $detail ?? $unit->arrival_date->format('j M').' → '.$unit->departure_date->format('j M'),
            message: $message,
        );
    }
}

<?php

namespace App\Services\NightAudit;

use App\Actions\NightAudit\NightAuditResult;
use App\Enums\AuditCheck;
use App\Enums\AuditExceptionType;
use App\Enums\BusinessDateStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PricingBasis;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Packages\InclusionSplit;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What stands between a property and a clean night audit: the units the audit would otherwise settle by policy or
 * leave as exceptions. It reads only. Every check is scoped to the property and to its current business date. A
 * blocker is a check with `AuditCheck::isBlocker()`. See docs/20-night-audit/02-readiness-panel.md.
 */
class AuditReadiness
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
    ) {}

    /**
     * Whether the current business date is over: the system date in the property's time zone is after it. A
     * property with no business date has nothing to wait for.
     */
    public function businessDateEnded(Property $property): bool
    {
        $date = $this->currentBusinessDate->date($property);

        return $date === null || $this->propertyToday->date($property)->toDateString() > $date->toDateString();
    }

    /**
     * Why a run that would start the audit must be forced, or null when it may go ahead: the business date has not
     * ended, or a blocker is open. A date that is already Auditing is under way, so nothing gates it.
     */
    public function gate(Property $property): ?string
    {
        if ($this->currentBusinessDate->get($property)?->status !== BusinessDateStatus::Open) {
            return null;
        }

        if (! $this->businessDateEnded($property)) {
            return NightAuditResult::REFUSAL_BUSINESS_DATE_NOT_ENDED;
        }

        return $this->blockers($property) === [] ? null : NightAuditResult::REFUSAL_BLOCKED_BY_READINESS;
    }

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
            ...$this->mastersNotBilled($property),
            ...$this->moneyHeldOnClosedBookings($property),
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
            ->map(fn (ReservationUnit $unit): ReadinessItem => $this->item(AuditCheck::ArrivalNotCheckedIn, $unit, $this->dayUseHours($unit)))
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
            ->map(fn (ReservationUnit $unit): ReadinessItem => $this->item(AuditCheck::DepartureNotCheckedOut, $unit, $this->endedDayUseDetail($unit), canExtend: $unit->stay_type !== StayType::DayUse && $unit->departure_date->toDateString() === $date->toDateString()))
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
     * Master and ThirdParty folios that are still Open with a balance above 0 after every unit has left: the reservation
     * has a CheckedOut unit and no CheckedIn or Confirmed one. With or without a payer, so a group whose organiser has
     * not paid is listed too. One item for each reservation, summing its folios. It is a warning: the audit never acts on it.
     *
     * @return list<ReadinessItem>
     */
    private function mastersNotBilled(Property $property): array
    {
        $folios = $this->billingFolios($property)
            ->whereHas('reservation.units', fn (Builder $units) => $units->where('status', UnitStatus::CheckedOut->value))
            ->whereDoesntHave('reservation.units', fn (Builder $units) => $units->whereIn('status', [UnitStatus::CheckedIn->value, UnitStatus::Confirmed->value]))
            ->get()
            ->filter(fn (Folio $folio): bool => bccomp($this->balance($folio), '0', 2) > 0);

        return $this->folioItems(AuditCheck::MasterNotBilled, $property, $folios, fn (string $amount): string => __(':amount owed', ['amount' => $amount]));
    }

    /**
     * Open folios holding money (a balance below 0) that nobody refunds: a Unit folio of a Cancelled or NoShow unit, or
     * a Master or ThirdParty folio of a reservation with at least one unit and none CheckedIn or Confirmed (cancelled,
     * missed or finished). One item for each reservation, summing the credits. A Closed folio is at 0, so only Open
     * folios are read. It matches the folios of the Deposit liability report.
     *
     * @return list<ReadinessItem>
     */
    private function moneyHeldOnClosedBookings(Property $property): array
    {
        $unitFolios = $this->billingFolios($property, [FolioType::Unit])
            ->whereHas('reservationUnit', fn (Builder $units) => $units->whereIn('status', [UnitStatus::Cancelled->value, UnitStatus::NoShow->value]))
            ->get();
        $bookingFolios = $this->billingFolios($property)
            ->whereHas('reservation.units')
            ->whereDoesntHave('reservation.units', fn (Builder $units) => $units->whereIn('status', [UnitStatus::CheckedIn->value, UnitStatus::Confirmed->value]))
            ->get();

        return $this->folioItems(
            AuditCheck::MoneyHeldOnClosedBooking,
            $property,
            $unitFolios->concat($bookingFolios)->unique('id')->filter(fn (Folio $folio): bool => bccomp($this->balance($folio), '0', 2) < 0)->sortBy('id'),
            fn (string $amount): string => __(':amount to refund', ['amount' => $amount]),
            credit: true,
        );
    }

    /**
     * Open folios of the property with their posted balance and what a row needs.
     *
     * @param  list<FolioType>  $types
     * @return Builder<Folio>
     */
    private function billingFolios(Property $property, array $types = [FolioType::Master, FolioType::ThirdParty]): Builder
    {
        return Folio::query()
            ->where('property_id', $property->id)
            ->whereIn('type', array_map(fn (FolioType $type): string => $type->value, $types))
            ->where('status', FolioStatus::Open->value)
            ->withSum('lines', 'amount')
            ->with(['reservation.booker', 'payerCompany:id,name'])
            ->orderBy('id');
    }

    private function balance(Folio $folio): string
    {
        return bcadd((string) ($folio->lines_sum_amount ?? '0'), '0', 2);
    }

    /**
     * One item for each reservation: the folios named in the detail, the amounts summed (credits as a positive figure),
     * the payer company or else the booker as the guest, and the first folio as the link target.
     *
     * @param  Collection<int, Folio>  $folios
     * @param  Closure(string): string  $phrase
     * @return list<ReadinessItem>
     */
    private function folioItems(AuditCheck $check, Property $property, Collection $folios, Closure $phrase, bool $credit = false): array
    {
        $currency = $property->loadMissing('settings')->settings?->currency->value;

        return $folios->groupBy('reservation_id')->map(function (Collection $group) use ($check, $currency, $phrase, $credit): ReadinessItem {
            $first = $group->first();
            $total = $group->reduce(fn (string $sum, Folio $folio): string => bcadd($sum, $credit ? bcmul($this->balance($folio), '-1', 2) : $this->balance($folio), 2), '0.00');
            $amount = trim($currency.' '.number_format((float) $total, 2));
            $names = match (true) {
                $group->count() > 1 => __(':count folios', ['count' => $group->count()]),
                $credit => $first->number,
                default => ($first->type === FolioType::Master ? __('Master') : __('Third party')).' '.$first->number,
            };

            return new ReadinessItem(
                check: $check,
                reservationId: $first->reservation_id,
                reservationUnitId: null,
                reference: $first->reservation->reference,
                guest: $group->first(fn (Folio $folio): bool => $folio->payerCompany !== null)?->payerCompany->name ?? $first->reservation->booker?->full_name,
                detail: $credit ? $phrase($amount).' · '.$names : $names.' · '.$phrase($amount),
                folioId: $first->id,
            );
        })->values()->all();
    }

    /**
     * @return list<ReadinessItem>
     */
    private function carriedExceptions(Property $property): array
    {
        return NightAuditException::query()
            ->where('property_id', $property->id)
            ->open()
            ->notStale()
            ->whereNotNull('reservation_unit_id')
            ->with(['reservationUnit.reservation.booker', 'reservationUnit.guests.guest', 'reservationUnit.nights.ratePlan', 'nightAudit.businessDate'])
            ->orderBy('id')
            ->get()
            ->map(fn (NightAuditException $exception): ReadinessItem => $this->item(
                AuditCheck::CarriedException,
                $exception->reservationUnit,
                $exception->type->label(),
                $exception->message,
                $exception->id,
                canFixPrice: $exception->type === AuditExceptionType::RoomCharge && $exception->message === InclusionSplit::REFUSAL_EXCEEDS_NIGHT_AMOUNT
                    && $exception->reservationUnit->nights->first(fn ($night): bool => $night->date->toDateString() === $exception->nightAudit->businessDate->date->toDateString())?->ratePlan->pricing_basis === PricingBasis::Package,
                exceptionType: $exception->type,
                exceptionDate: $exception->nightAudit->businessDate->date->toDateString(),
            ))
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
            ->with(['reservation.booker', 'guests.guest', 'dayUse'])
            ->orderBy('id');
    }

    /**
     * "Day use · ended 14:00" for a day use unit, otherwise null (the item then shows its dates).
     */
    private function endedDayUseDetail(ReservationUnit $unit): ?string
    {
        if ($unit->stay_type !== StayType::DayUse) {
            return null;
        }

        $endsAt = $unit->dayUse?->ends_at;

        return $endsAt === null ? null : __('Day use · ended :time', ['time' => substr($endsAt, 0, 5)]);
    }

    /**
     * "Day use · 10:00–14:00" for a day use unit, otherwise null (the item then shows its dates).
     */
    private function dayUseHours(ReservationUnit $unit): ?string
    {
        $block = $unit->stay_type === StayType::DayUse ? $unit->dayUse : null;

        return $block === null ? null : __('Day use · :start–:end', ['start' => substr($block->starts_at, 0, 5), 'end' => substr($block->ends_at, 0, 5)]);
    }

    private function item(AuditCheck $check, ReservationUnit $unit, ?string $detail = null, ?string $message = null, ?int $exceptionId = null, bool $canExtend = false, bool $canFixPrice = false, ?AuditExceptionType $exceptionType = null, ?string $exceptionDate = null): ReadinessItem
    {
        return new ReadinessItem(
            check: $check,
            reservationId: $unit->reservation_id,
            reservationUnitId: $unit->id,
            reference: $unit->reservation->reference,
            guest: $unit->guests->firstWhere('is_primary', true)?->guest->full_name ?? $unit->reservation->booker?->full_name,
            detail: $detail ?? $unit->arrival_date->format('j M').' → '.$unit->departure_date->format('j M'),
            message: $message,
            exceptionId: $exceptionId,
            canFixPrice: $canFixPrice,
            exceptionType: $exceptionType,
            dayUse: $unit->stay_type === StayType::DayUse,
            canExtend: $canExtend,
            exceptionDate: $exceptionDate,
        );
    }
}

<?php

namespace App\Services\Reservations;

use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Reservations\AssignmentResult;
use App\Data\Reservations\CancelAllPreviewData;
use App\Data\Reservations\CheckInAllPreviewData;
use App\Data\Reservations\CheckOutDamageItemData;
use App\Data\Reservations\FreeUnitData;
use App\Data\Reservations\MoveTargetData;
use App\Data\Reservations\UnitCheckInPreviewData;
use App\Data\Reservations\UnitCheckOutPreviewData;
use App\Data\Reservations\UnitDatesPreviewData;
use App\Data\Reservations\UnitEarlyDepartureData;
use App\Data\Reservations\UnitFeePreviewData;
use App\Data\Reservations\UnitLateCheckOutPreviewData;
use App\Data\Reservations\UnitMovePreviewData;
use App\Data\Reservations\UnitMoveStayPreviewData;
use App\Data\Reservations\UnitOverdueData;
use App\Data\Reservations\UnitReinstatePreviewData;
use App\Data\Reservations\UnitUndoPreviewData;
use App\Enums\CheckInRoomState;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Availability\DayUseAvailability;
use App\Services\Availability\FreeUnits;
use App\Services\Catalogues\PolicyChargeCalculator;
use App\Services\Catalogues\TaxCalculator;
use App\Services\CityLedger\CreditCheck;
use App\Services\FrontDesk\AuditPendingNotice;
use App\Services\FrontDesk\CheckInCollection;
use App\Services\FrontDesk\CheckOutSettlement;
use App\Services\FrontDesk\DamageChargeItem;
use App\Services\FrontDesk\EarlyCheckOutRule;
use App\Services\FrontDesk\PendingNightQuote;
use App\Services\FrontDesk\StayDay;
use App\Services\FrontDesk\UndoCheckInRule;
use App\Services\FrontDesk\UndoCheckOutRule;
use App\Services\Guests\CountryOptions;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Platform\Impersonation;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use App\Services\Rates\RestrictionCheck;
use App\Services\Registration\RegistrationCardRequirement;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What the unit action forms show when they open: the policy fee, the price after a reinstate and the price after a
 * date change. It reads only and never locks or writes. The Actions work everything out again inside their own
 * transaction, so a preview that is out of date is refused on submit (`fee_changed`) and never posts a wrong amount.
 * See docs/18-front-desk/01-check-in-and-check-out.md#how-the-forms-work.
 */
class UnitActionPreview
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PolicyChargeCalculator $policyChargeCalculator,
        private RateQuoteService $rateQuoteService,
        private RestrictionCheck $restrictionCheck,
        private ReservationRefusals $refusals,
        private RegistrationCardRequirement $registrationCardRequirement,
        private UnitNationality $unitNationality,
        private CreditCheck $creditCheck,
        private CheckInCollection $checkInCollection,
        private Impersonation $impersonation,
        private CheckOutSettlement $checkOutSettlement,
        private CountryOptions $countryOptions,
        private FreeUnits $freeUnits,
        private DayUseAvailability $dayUseAvailability,
        private TaxCalculator $taxCalculator,
        private EarlyCheckOutRule $earlyCheckOutRule,
        private UndoCheckInRule $undoCheckInRule,
        private UndoCheckOutRule $undoCheckOutRule,
        private StayDay $stayDay,
        private PendingNightQuote $pendingNightQuote,
        private AuditPendingNotice $auditPendingNotice,
        private DamageChargeItem $damageChargeItem,
    ) {}

    /**
     * Tonight's room, the registration card status, the checks the user may override and Collect now.
     */
    public function checkIn(ReservationUnit $unit, User $user): UnitCheckInPreviewData
    {
        $property = $unit->property()->with('settings')->firstOrFail();
        $businessDate = $this->currentBusinessDate->date($property);

        if ($businessDate === null) {
            return UnitCheckInPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_NO_BUSINESS_DATE));
        }

        $tonight = $unit->nights()->with(['room', 'bed'])->whereDate('date', $this->stayDay->forCheckIn($unit, $businessDate)->toDateString())->first();
        $room = $tonight->room->number ?? $tonight?->bed?->label;
        $cardRequired = (bool) $property->settings?->card_required_for_check_in;
        $needsNationality = $this->unitNationality->for($unit) === null
            && Tax::query()->where('property_id', $unit->property_id)->where('enabled', true)->where('foreign_guests_only', true)->exists();
        $company = $unit->reservation()->firstOrFail()->company;
        $credit = $company === null ? null : $this->creditCheck->check($company);
        $collection = $user->can(PermissionEnum::PaymentRecord->value) ? $this->checkInCollection->for($unit) : null;

        return new UnitCheckInPreviewData(
            room: $room,
            roomState: match (true) {
                $room !== null => CheckInRoomState::Assigned,
                (bool) $property->settings?->auto_assign_rooms => CheckInRoomState::Auto,
                default => CheckInRoomState::None,
            },
            cardsMissing: $cardRequired ? $this->registrationCardRequirement->missingFor($unit, $property)->count() : 0,
            cardRequired: $cardRequired,
            canCheckInWithoutCard: $user->can(PermissionEnum::FrontDeskCheckInWithoutCard->value),
            needsNationality: $needsNationality,
            countries: $needsNationality ? $this->countryOptions->options() : [],
            creditExceeded: $credit !== null && ! $credit->allowed,
            canOverrideCredit: $user->can(PermissionEnum::CompanyCreditOverride->value),
            collection: $collection === null || $collection->isEmpty() ? null : $collection,
            error: null,
            auditPending: $this->auditPendingNotice->block($property, $this->stayDay->forCheckIn($unit, $businessDate)->gt($businessDate)
                ? __('That\'s fine. Tonight\'s night audit charges :date.', ['date' => $businessDate->addDay()->format('j M')])
                : null),
        );
    }

    /**
     * The rows of the group Check in all form: each Confirmed unit, ready when the business date is inside its stay.
     * A row that is not ready says why in words the front desk can act on.
     */
    public function checkInAll(Reservation $reservation, User $user): CheckInAllPreviewData
    {
        $property = $reservation->property()->with('settings')->firstOrFail();
        $businessDate = $this->currentBusinessDate->date($property);

        if ($businessDate === null) {
            return CheckInAllPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_NO_BUSINESS_DATE));
        }

        $today = $businessDate->toDateString();
        $cardRequired = (bool) $property->settings?->card_required_for_check_in;
        $autoAssign = (bool) $property->settings?->auto_assign_rooms;
        $rows = [];

        $units = $reservation->units()
            ->where('status', UnitStatus::Confirmed)
            ->with(['roomType', 'guests.guest', 'nights' => fn ($nights) => $nights->whereBetween('date', [$today, $businessDate->addDay()->toDateString()]), 'nights.room', 'nights.bed'])
            ->orderBy('id')
            ->get();

        foreach ($units as $unit) {
            $day = $this->stayDay->forCheckIn($unit, $businessDate)->toDateString();
            $tonight = $unit->nights->first(fn ($night): bool => $night->date->toDateString() === $day);
            $arrival = $unit->arrival_date->toDateString();
            $ready = $day >= $arrival && $day < $unit->departure_date->toDateString();
            $room = $tonight->room->number ?? $tonight?->bed?->label;

            $rows[] = [
                'unitId' => $unit->id,
                'label' => $unit->roomType->name,
                'guest' => $unit->guests->firstWhere('is_primary', true)?->guest->full_name,
                'room' => $room,
                'cardsMissing' => $cardRequired ? $this->registrationCardRequirement->missingFor($unit, $property)->count() : 0,
                'ready' => $ready,
                'note' => match (true) {
                    ! $ready && $day < $arrival => $this->notArrivedNote($unit, $businessDate),
                    ! $ready => __('Cannot check in: the stay ended on :date.', ['date' => $unit->departure_date->format('d M Y')]),
                    $room === null && $autoAssign => __('No room yet. One is given at check-in.'),
                    $room === null => __('No room for tonight. Assign a room before check-in.'),
                    default => null,
                },
            ];
        }

        return new CheckInAllPreviewData($rows, $user->can(PermissionEnum::FrontDeskCheckInWithoutCard->value), null);
    }

    /**
     * Why a unit that arrives after the business date cannot check in, and what the front desk can do: change the
     * dates first to check the guest in early.
     */
    private function notArrivedNote(ReservationUnit $unit, CarbonInterface $businessDate): string
    {
        $days = (int) CarbonImmutable::instance($businessDate)->startOfDay()->diffInDays($unit->arrival_date->startOfDay());
        $when = $days === 1 ? __('tomorrow') : __('in :days days', ['days' => $days]);

        return __('Cannot check in yet: booked to arrive :date (:when). To check in today, change the dates first.', [
            'date' => $unit->arrival_date->format('d M Y'),
            'when' => $when,
        ]);
    }

    /**
     * The Move room form for a first night: the current room or bed, the room types the unit can move to and the free
     * rooms or beds of the chosen type (its own when none is chosen). An unknown or unlinked type falls back to the
     * unit's own. A day use unit has no night to pick and keeps its room type: the form shows the current room and
     * the rooms free for the block's hours (`moveDayUse()`). With `$withTargets` it also lists every row the Timeline
     * may drop the unit on (`moveTargets()`).
     */
    public function move(ReservationUnit $unit, CarbonInterface $fromNight, ?int $roomTypeId, bool $withTargets = false): UnitMovePreviewData
    {
        if ($unit->stay_type === StayType::DayUse) {
            return $this->moveDayUse($unit);
        }

        $from = CarbonImmutable::instance($fromNight)->startOfDay();

        if ($from->lt($unit->arrival_date->startOfDay()) || $from->gte($unit->departure_date->startOfDay())) {
            return UnitMovePreviewData::refused($this->refusals->message(AssignmentResult::REFUSAL_NIGHT_INVALID));
        }

        $own = $unit->roomType()->firstOrFail();
        $linkedIds = $unit->ratePlan()->firstOrFail()->roomTypes()->pluck('room_types.id');
        $types = RoomType::query()
            ->where('property_id', $unit->property_id)
            ->where(fn ($query) => $query
                ->where(fn ($linked) => $linked->whereIn('id', $linkedIds)->where('sell_unit', $own->sell_unit)->active())
                ->orWhere('id', $own->id))
            ->orderBy('name')
            ->get(['id', 'name']);
        $chosen = $types->firstWhere('id', $roomTypeId) ?? $types->firstWhere('id', $own->id);
        $night = $unit->nights()->with(['room', 'bed'])->whereDate('date', $from->toDateString())->first();

        return new UnitMovePreviewData(
            current: $night->room->number ?? $night?->bed?->label,
            roomTypes: array_values($types->map(fn (RoomType $type): array => ['id' => $type->id, 'name' => $type->name])->all()),
            roomTypeId: $chosen?->id,
            sellsBeds: $own->sell_unit === SellUnit::Bed,
            reasonRequired: $night === null || $night->room_id !== null || $night->bed_id !== null,
            units: $chosen === null ? [] : $this->freeUnits->forStay(RoomType::query()->findOrFail($chosen->id), $from, $unit->departure_date, $unit->id, $unit->arrival_time, $unit->departure_time),
            error: null,
            targets: $withTargets ? $this->moveTargets($unit, $from, $own, array_values($types->map(fn (RoomType $type): int => $type->id)->all())) : [],
        );
    }

    /**
     * The rows a drag may drop the unit on, with the first night each is free from. Its own room type is read from
     * `$from` to the departure. Another room type needs the whole stay (`whole_stay_required`), so it is listed only
     * when `$from` is the arrival, and only its rows free from the arrival.
     *
     * @param  list<int>  $typeIds  The room types the unit can move to, its own included.
     * @return list<MoveTargetData>
     */
    private function moveTargets(ReservationUnit $unit, CarbonImmutable $from, RoomType $own, array $typeIds): array
    {
        $prefix = $own->sell_unit === SellUnit::Bed ? 'bed-' : 'room-';
        $isWholeStay = $from->equalTo($unit->arrival_date->startOfDay());
        $targets = [];

        foreach (RoomType::query()->whereIn('id', $typeIds)->orderBy('id')->get() as $type) {
            if ($type->id !== $own->id && ! $isWholeStay) {
                continue;
            }

            foreach ($this->freeUnits->freeFrom($type, $from, $unit->departure_date, $unit->id, $unit->arrival_time, $unit->departure_time) as $id => $freeFrom) {
                if ($type->id === $own->id || $freeFrom === $from->toDateString()) {
                    $targets[] = new MoveTargetData($prefix.$id, $type->id, $freeFrom);
                }
            }
        }

        return $targets;
    }

    /**
     * The drop of a Confirmed bar on new dates and a room or bed, as `MoveUnitStay` will work it out: the dates preview,
     * whether the target is free on every new night (the unit's own nights do not count), whether its room type is
     * allowed and whether a reason is needed. It checks the same things as the Actions but never writes.
     */
    public function moveStay(ReservationUnit $unit, CarbonInterface $arrival, CarbonInterface $departure, Room|Bed $target): UnitMoveStayPreviewData
    {
        if ($unit->status !== UnitStatus::Confirmed) {
            return UnitMoveStayPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_INVALID_STATUS));
        }

        $dates = $this->dates($unit, $arrival, $departure);

        if ($dates->error !== null) {
            return UnitMoveStayPreviewData::refused($dates->error);
        }

        $isBed = $target instanceof Bed;
        $own = $unit->roomType()->firstOrFail();
        $targetType = RoomType::query()->findOrFail($isBed ? $target->room()->firstOrFail()->room_type_id : $target->room_type_id);
        $isAllowed = $targetType->sell_unit === ($isBed ? SellUnit::Bed : SellUnit::Room)
            && $this->freeUnits->isSellable($targetType, $target->id)
            && ($targetType->id === $own->id || ($targetType->sell_unit === $own->sell_unit && $targetType->archived_at === null && $unit->ratePlan()->firstOrFail()->roomTypes()->whereKey($targetType->id)->exists()));
        $taken = $isAllowed
            ? $this->freeUnits->firstTakenNight($targetType, $target->id, $arrival, $departure, $unit->id, $unit->arrival_time, $unit->departure_time)
            : null;
        $nights = $unit->nights()->get();
        $keepsRoom = $nights->isNotEmpty() && $nights->every(fn (UnitNight $night): bool => ($isBed ? $night->bed_id : $night->room_id) === $target->id);
        $holdsRoom = $nights->contains(fn (UnitNight $night): bool => $night->room_id !== null || $night->bed_id !== null);

        return new UnitMoveStayPreviewData(
            dates: $dates,
            targetFree: $isAllowed && $taken === null,
            firstTakenNight: $taken?->toDateString(),
            roomTypeAllowed: $isAllowed,
            reasonRequired: $holdsRoom && ! $keepsRoom,
            error: null,
        );
    }

    private function moveDayUse(ReservationUnit $unit): UnitMovePreviewData
    {
        $dayUse = $unit->dayUse()->with('room')->firstOrFail();
        $roomType = $unit->roomType()->firstOrFail();

        return new UnitMovePreviewData(
            current: $dayUse->room?->number,
            roomTypes: [['id' => $roomType->id, 'name' => $roomType->name]],
            roomTypeId: $roomType->id,
            sellsBeds: false,
            reasonRequired: true,
            units: array_values(array_filter(
                $this->dayUseAvailability->freeRooms($roomType, $dayUse->date, $dayUse->starts_at, $dayUse->ends_at, $unit->id),
                fn (FreeUnitData $room): bool => $room->id !== $dayUse->room_id,
            )),
            error: null,
            isDayUse: true,
            hoursLabel: __('Day use · :from–:to', ['from' => substr($dayUse->starts_at, 0, 5), 'to' => substr($dayUse->ends_at, 0, 5)]),
        );
    }

    /**
     * The unit folio balance split by room, the security deposit held, another room's scheduled nights still charged
     * here, and whether the user may collect, refund and move charges.
     */
    public function checkOut(ReservationUnit $unit, User $user, bool $leftYesterday = false, bool $stayedLastNight = false, ?string $damageAmount = null): UnitCheckOutPreviewData
    {
        $preview = $this->checkOutFigures($unit, $user, $leftYesterday, $stayedLastNight);

        return $preview->error === null ? $this->withDamage($preview, $unit, $user, $damageAmount) : $preview;
    }

    /**
     * The damage charge item the user may post, and with `$damageAmount` above 0 what it adds with tax. The damage
     * posts before the refund, so the default refund is worked out again from the balance after it (and after the
     * early departure fee).
     */
    private function withDamage(UnitCheckOutPreviewData $preview, ReservationUnit $unit, User $user, ?string $damageAmount): UnitCheckOutPreviewData
    {
        $item = $this->damageChargeItem->offeredTo($unit->property, $user);

        if ($item === null) {
            return $preview;
        }

        $preview->damageItem = CheckOutDamageItemData::fromModel($item);

        if ($damageAmount === null || ! BigDecimal::of($damageAmount)->isPositive()) {
            return $preview;
        }

        $preview->damageTotal = $this->damageChargeItem->total($item->loadMissing('transactionCode'), $unit, $damageAmount);

        if ($preview->damageTotal !== null) {
            $balance = BigDecimal::of($preview->balance)->plus($preview->early === null ? '0' : $preview->early->feeTotal)->plus($preview->damageTotal);
            $refunds = $this->checkOutSettlement->refunds($this->checkOutSettlement->folios($unit), $balance);
            $preview->refundDefault = (string) $refunds['default']->toScale(2);
            $preview->refundParts = $refunds['parts'];
        }

        return $preview;
    }

    private function checkOutFigures(ReservationUnit $unit, User $user, bool $leftYesterday, bool $stayedLastNight): UnitCheckOutPreviewData
    {
        $property = $unit->property()->firstOrFail();
        $businessDate = $this->currentBusinessDate->date($property);
        $overdueLines = $businessDate === null ? [] : $this->pendingNightQuote->overdueLines($unit, $businessDate);
        $pendingLines = $businessDate === null || $leftYesterday ? [] : ($stayedLastNight && $overdueLines !== [] ? $overdueLines : $this->pendingNightQuote->lines($unit, $businessDate));
        $preview = $this->checkOutSettlement->for($unit, $this->impersonation->mayRefund($user), $user->can(PermissionEnum::PaymentRecord->value), $user->can(PermissionEnum::FolioTransfer->value), $pendingLines);

        if ($overdueLines !== []) {
            $nightTotal = $this->pendingNightQuote->total($overdueLines);
            $preview->overdue = new UnitOverdueData(
                nightDate: $businessDate->toDateString(),
                nightTotal: (string) $nightTotal->toScale(2),
                lines: $overdueLines,
                balanceIfStayed: (string) BigDecimal::of($preview->balance)->plus($stayedLastNight ? '0' : $nightTotal)->toScale(2),
                stayed: $stayedLastNight,
            );
        }

        if ($unit->status === UnitStatus::CheckedIn && $businessDate !== null) {
            $leftYesterdayRefusal = $this->earlyCheckOutRule->refusal($unit, $businessDate->toDateString(), true);
            $preview->leftYesterdayChoice = $leftYesterdayRefusal === null || $leftYesterdayRefusal === UnitStayResult::REFUSAL_ARRIVED_TODAY;
            $preview->leftYesterdayBlocked = $leftYesterdayRefusal === UnitStayResult::REFUSAL_ARRIVED_TODAY
                ? __('Arrived on :date, so there is no night to remove. If they checked in by mistake, use Undo check-in.', ['date' => $businessDate->format('j M')])
                : null;
        }

        if ($unit->status !== UnitStatus::CheckedIn || $businessDate === null || (! $leftYesterday && $this->stayDay->forCheckOut($property, $businessDate)->toDateString() >= $unit->departure_date->toDateString())) {
            $preview->auditPending = $businessDate === null ? null : $this->auditPendingNotice->block($property, $pendingLines === [] ? null : $this->addedNowNotice($businessDate));

            return $preview;
        }

        $early = $this->earlyCheckOut($unit, $businessDate->toImmutable(), $preview, $leftYesterday);

        if ($early->error === null && $early->early !== null) {
            $removed = $unit->nights()->whereDate('date', '>=', $early->early->newDeparture)->orderBy('date')->pluck('date')->map(fn ($date): CarbonImmutable => CarbonImmutable::parse($date))->all();
            $removed = array_values($removed);
            $gone = $removed === [] ? '' : ' '.__(':dates removed.', ['dates' => $this->auditPendingNotice->dates($removed)]);
            $early->auditPending = $this->auditPendingNotice->block($property, $leftYesterday
                ? __('Last night (:date) is not charged.', ['date' => $businessDate->format('j M')]).$gone
                : $this->addedNowNotice($businessDate).$gone);
        }

        return $early;
    }

    private function addedNowNotice(CarbonInterface $businessDate): string
    {
        return __('Last night (:date) is added to the bill now. The night audit will skip it.', ['date' => $businessDate->format('j M')]);
    }

    /**
     * The early departure fee `EarlyCheckOut` will post, with its tax, and the nights it removes. The new departure is
     * the stay day, or the business date for Guest actually left yesterday (`$leftYesterday`). `leftYesterdayAvailable`
     * says whether that choice is open for this unit.
     */
    private function earlyCheckOut(ReservationUnit $unit, CarbonImmutable $businessDate, UnitCheckOutPreviewData $preview, bool $leftYesterday): UnitCheckOutPreviewData
    {
        $refusal = $this->earlyCheckOutRule->refusal($unit, $businessDate->toDateString(), $leftYesterday);

        if ($refusal !== null) {
            return UnitCheckOutPreviewData::refused($this->refusals->message($refusal, $businessDate, $refusal === UnitStayResult::REFUSAL_NIGHT_AUDIT_PENDING ? [$businessDate->format('j M')] : []));
        }

        $property = $unit->property()->firstOrFail();
        $policy = $this->policy($unit, SystemTransactionCode::EarlyDepartureRevenue);
        $code = $property->transactionCodes()->where('system_key', SystemTransactionCode::EarlyDepartureRevenue->value)->where('active', true)->first();

        if ($policy === null || $code === null) {
            return UnitCheckOutPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_POLICY_NOT_FOUND));
        }

        $newDeparture = $this->earlyCheckOutRule->newDeparture($property, $businessDate, $leftYesterday);
        $charge = $this->policyChargeCalculator->calculate($policy, $unit, $newDeparture);
        $tax = $this->taxCalculator->calculate($property, $code, $charge->amount, '1', $this->unitNationality->for($unit));

        if (! $tax->isCalculated()) {
            return UnitCheckOutPreviewData::refused($this->refusals->message((string) $tax->refusalReason));
        }

        // A free fee posts nothing, not even a flat tax, as in ProcessEarlyDeparture.
        $feeTotal = $charge->isFree() ? '0.00' : (string) $tax->grossAmount->getAmount();

        $preview->early = new UnitEarlyDepartureData(
            departure: $unit->departure_date->toDateString(),
            nightsRemoved: $unit->nights()->whereDate('date', '>=', $newDeparture->toDateString())->count(),
            fee: (string) $charge->amount->getAmount(),
            feeTotal: $feeTotal,
            withinFreeWindow: $charge->withinFreeWindow,
            newDeparture: $newDeparture->toDateString(),
            leftYesterdayAvailable: $this->earlyCheckOutRule->isOffered($unit, $businessDate->toDateString(), true),
        );

        // The fee is posted before the refund, so the credit the refund gives back is the one after the fee.
        $refunds = $this->checkOutSettlement->refunds($this->checkOutSettlement->folios($unit), BigDecimal::of($preview->balance)->plus($feeTotal));
        $preview->refundDefault = (string) $refunds['default']->toScale(2);
        $preview->refundParts = $refunds['parts'];

        return $preview;
    }

    /**
     * The late check-out policy fee and the times the form shows.
     */
    public function lateCheckOut(ReservationUnit $unit): UnitLateCheckOutPreviewData
    {
        $policy = $this->policy($unit, SystemTransactionCode::LateCheckoutRevenue);

        if ($policy === null) {
            return UnitLateCheckOutPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_POLICY_NOT_FOUND));
        }

        return new UnitLateCheckOutPreviewData(
            fee: (string) $this->policyChargeCalculator->calculate($policy, $unit)->amount->getAmount(),
            departureTime: substr($unit->departure_time, 0, 5),
            standardTime: substr((string) $unit->property()->with('settings')->firstOrFail()->settings?->check_out_time, 0, 5),
            error: null,
        );
    }

    /**
     * The Undo check-in form: why it cannot be undone, or the charges and payments that stay on the folios.
     */
    public function undoCheckIn(ReservationUnit $unit): UnitUndoPreviewData
    {
        $businessDate = $this->currentBusinessDate->get($unit->property()->firstOrFail());

        if ($businessDate === null) {
            return UnitUndoPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_NO_BUSINESS_DATE));
        }

        $refusal = $this->undoCheckInRule->refusal($unit, $businessDate);

        if ($refusal !== null) {
            return UnitUndoPreviewData::refused($this->refusals->message($refusal));
        }

        return $this->undoPreview($unit, []);
    }

    /**
     * The Undo check-out form: why it cannot be undone, or the folios it reopens and what stays on them.
     */
    public function undoCheckOut(ReservationUnit $unit): UnitUndoPreviewData
    {
        $businessDate = $this->currentBusinessDate->get($unit->property()->firstOrFail());

        if ($businessDate === null) {
            return UnitUndoPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_NO_BUSINESS_DATE));
        }

        $refusal = $this->undoCheckOutRule->refusal($unit, $businessDate);

        if ($refusal !== null) {
            return UnitUndoPreviewData::refused($this->refusals->message($refusal));
        }

        $reopens = array_values($this->undoCheckOutRule->foliosToReopen($unit)
            ->map(fn (Folio $folio): string => $folio->invoice_number === null ? $folio->number : $folio->number.' · '.$folio->invoice_number)
            ->all());

        return $this->undoPreview($unit, $reopens);
    }

    /**
     * @param  list<string>  $reopens
     */
    private function undoPreview(ReservationUnit $unit, array $reopens): UnitUndoPreviewData
    {
        $folioIds = $this->checkOutSettlement->folios($unit)->modelKeys();
        $lines = FolioLine::query()->whereIn('folio_id', $folioIds)->whereNull('parent_line_id')->orderBy('id')->get();
        $charges = array_values($lines->where('kind', TransactionKind::Charge)->map(fn (FolioLine $line): array => ['description' => $line->description, 'amount' => (string) $line->amount])->all());
        $paid = $lines->where('kind', TransactionKind::Payment)->reduce(fn (BigDecimal $sum, FolioLine $line): BigDecimal => $sum->minus((string) $line->amount), BigDecimal::zero());

        return new UnitUndoPreviewData($charges, (string) $paid->toScale(2), $reopens, null);
    }

    /**
     * The rows of the Cancel reservation form: every unit with its cancellation fee (before and after tax) on the
     * business date, the money held on the reservation and the company portal request. Only a Confirmed unit can be
     * ticked. The fee is the figure `CancelUnit` computes, so the Dialog and a Cancel unit agree.
     */
    public function cancelAll(Reservation $reservation): CancelAllPreviewData
    {
        $property = $reservation->property()->firstOrFail();
        $businessDate = $this->currentBusinessDate->date($property);

        if ($businessDate === null) {
            return CancelAllPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_NO_BUSINESS_DATE));
        }

        $policy = RoomRevenuePolicy::query()->where('property_id', $property->id)->where('code', SystemTransactionCode::CancellationRevenue)->first();
        $code = $property->transactionCodes()->where('system_key', SystemTransactionCode::CancellationRevenue->value)->where('active', true)->first();

        if ($policy === null || $code === null) {
            return CancelAllPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_POLICY_NOT_FOUND));
        }

        $units = $reservation->units()->with(['roomType', 'guests.guest', 'dayUse', 'reservation.booker'])->withCount(['addOns as unposted_add_ons_count' => fn ($query) => $query->whereNull('folio_line_id')])->orderBy('id')->get();
        $stayLabel = app(StayLabel::class);
        $rows = [];

        foreach ($units as $unit) {
            $row = [
                'unitId' => $unit->id,
                'label' => $unit->roomType->name,
                'guest' => $unit->guests->firstWhere('is_primary', true)?->guest->full_name,
                'arrival' => $unit->arrival_date->toDateString(),
                'departure' => $unit->departure_date->toDateString(),
                'stayLabel' => $stayLabel->forUnit($unit),
                'canCancel' => false,
                'fee' => null,
                'feeTotal' => null,
                'withinFreeWindow' => false,
                'unpostedAddOns' => 0,
                'note' => null,
            ];

            if ($unit->status !== UnitStatus::Confirmed) {
                $row['note'] = $unit->status === UnitStatus::CheckedIn
                    ? __('Checked in: use Check out or Early check-out.')
                    : __('Already :status.', ['status' => strtolower($unit->status->label())]);
                $rows[] = $row;

                continue;
            }

            $charge = $this->policyChargeCalculator->calculate($policy, $unit);
            $tax = $this->taxCalculator->calculate($property, $code, $charge->amount, '1', $this->unitNationality->for($unit));

            if (! $tax->isCalculated()) {
                $row['note'] = $this->refusals->message((string) $tax->refusalReason);
                $rows[] = $row;

                continue;
            }

            $row['canCancel'] = true;
            $row['fee'] = (string) $charge->amount->getAmount();
            $row['feeTotal'] = $charge->isFree() ? '0.00' : (string) $tax->grossAmount->getAmount();
            $row['withinFreeWindow'] = $charge->withinFreeWindow;
            $row['unpostedAddOns'] = (int) $unit->unposted_add_ons_count;
            $rows[] = $row;
        }

        $paid = FolioLine::query()
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->leftJoin('transaction_codes', 'transaction_codes.id', '=', 'folio_lines.transaction_code_id')
            ->where('folios.reservation_id', $reservation->id)
            ->where('folios.status', '!=', FolioStatus::Voided->value)
            ->where('folio_lines.kind', TransactionKind::Payment->value)
            ->whereRaw('transaction_codes.system_key is distinct from ?', [SystemTransactionCode::DirectBillPayment->value])
            ->sum(DB::raw('-folio_lines.amount'));

        $request = null;

        if ($reservation->cancellation_requested_at !== null) {
            $reservation->loadMissing(['company', 'cancellationRequester']);
            $request = [
                'by' => $reservation->company->name ?? $reservation->cancellationRequester->name ?? __('The booker'),
                'at' => $reservation->cancellation_requested_at->toDateString(),
            ];
        }

        return new CancelAllPreviewData($rows, $policy->free_until_days, (string) BigDecimal::of((string) ($paid ?: 0))->toScale(2), $request, null);
    }

    /**
     * The fee of the cancellation or no-show policy on the business date, for the Cancel unit and Mark no-show forms.
     */
    public function fee(ReservationUnit $unit, SystemTransactionCode $code): UnitFeePreviewData
    {
        $businessDate = $this->currentBusinessDate->date($unit->property()->firstOrFail());

        if ($businessDate === null) {
            return UnitFeePreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_NO_BUSINESS_DATE));
        }

        $policy = $this->policy($unit, $code);

        if ($policy === null) {
            return UnitFeePreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_POLICY_NOT_FOUND));
        }

        $charge = $this->policyChargeCalculator->calculate($policy, $unit);
        $isCancellation = $code === SystemTransactionCode::CancellationRevenue;

        return new UnitFeePreviewData(
            fee: (string) $charge->amount->getAmount(),
            withinFreeWindow: $charge->withinFreeWindow,
            freeUntilDays: $isCancellation ? $policy->free_until_days : null,
            daysBeforeArrival: $isCancellation ? (int) $businessDate->diffInDays($unit->arrival_date->toImmutable()->startOfDay(), false) : null,
            unpostedAddOns: $unit->addOns()->whereNull('folio_line_id')->count(),
            error: null,
        );
    }

    /**
     * The unit total as booked and as `ReinstateUnit` will price it again (a stay override keeps its night amount).
     */
    public function reinstate(ReservationUnit $unit): UnitReinstatePreviewData
    {
        $currency = $this->currency($unit);

        if ($unit->stay_type === StayType::DayUse) {
            return $this->reinstateDayUse($unit);
        }

        $nights = $unit->nights()->orderBy('date')->get();
        $quote = $this->rateQuoteService->quote(new RateQuoteRequest(
            $unit->ratePlan()->firstOrFail(),
            $unit->roomType()->firstOrFail(),
            $unit->arrival_date->toImmutable()->startOfDay(),
            $unit->departure_date->toImmutable()->startOfDay(),
            $unit->adults,
            $this->childAges($unit),
            1,
        ));

        if ($quote->isRefused()) {
            return UnitReinstatePreviewData::refused($this->refusals->message((string) $quote->refusal, $quote->refusalDate));
        }

        $booked = $nights->keyBy(fn (UnitNight $night): string => $night->date->toDateString());
        $new = Money::zero($currency);

        foreach ($quote->nights as $price) {
            $override = $booked->get($price->date->toDateString())?->override_amount;
            $new = $new->plus($override ?? $price->net());
        }

        return new UnitReinstatePreviewData(
            previousTotal: (string) $this->total($nights, $currency)->getAmount(),
            newTotal: (string) $new->getAmount(),
            error: null,
        );
    }

    /**
     * The block price as booked and as reinstated. An override stays without quoting again.
     */
    private function reinstateDayUse(ReservationUnit $unit): UnitReinstatePreviewData
    {
        $dayUse = $unit->dayUse()->firstOrFail();

        if ($dayUse->override_amount !== null) {
            return new UnitReinstatePreviewData(previousTotal: $dayUse->amount, newTotal: $dayUse->override_amount, error: null);
        }

        $quote = $this->rateQuoteService->quoteDayUse($unit->roomType()->firstOrFail(), $unit->ratePlan()->firstOrFail(), $dayUse->date, $dayUse->hours);

        if ($quote->isRefused()) {
            return UnitReinstatePreviewData::refused($this->refusals->message((string) $quote->refusal, $quote->refusalDate));
        }

        return new UnitReinstatePreviewData(
            previousTotal: $dayUse->amount,
            newTotal: (string) $quote->amount->getAmount(),
            error: null,
        );
    }

    /**
     * The unit total after the dates change, as `ChangeUnitDates` will work it out: nights that stay keep their
     * stored prices and added nights are quoted alone, run by run.
     */
    public function dates(ReservationUnit $unit, CarbonInterface $arrival, CarbonInterface $departure): UnitDatesPreviewData
    {
        $newArrival = CarbonImmutable::instance($arrival)->startOfDay();
        $newDeparture = CarbonImmutable::instance($departure)->startOfDay();
        $oldArrival = $unit->arrival_date->toImmutable()->startOfDay();
        $oldDeparture = $unit->departure_date->toImmutable()->startOfDay();

        if ($newDeparture->lte($newArrival)) {
            return UnitDatesPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_INVALID_STAY));
        }

        $currency = $this->currency($unit);
        $nights = $unit->nights()->orderBy('date')->get();
        $previous = $this->total($nights, $currency);
        $kept = $nights->filter(fn (UnitNight $night): bool => $night->date->gte($newArrival) && $night->date->lt($newDeparture));
        $total = $this->total($kept, $currency);
        $added = $this->addedRuns($newArrival, $newDeparture, $oldArrival, $oldDeparture);
        $roomType = $unit->roomType()->firstOrFail();
        $ratePlan = $unit->ratePlan()->firstOrFail();

        foreach ($added as $run) {
            $quote = $this->rateQuoteService->quote(new RateQuoteRequest(
                $ratePlan,
                $roomType,
                $run[0],
                $run[array_key_last($run)]->addDay(),
                $unit->adults,
                $this->childAges($unit),
                1,
            ));

            if ($quote->isRefused()) {
                return UnitDatesPreviewData::refused($this->refusals->message((string) $quote->refusal, $quote->refusalDate));
            }

            $total = $total->plus($quote->total);
        }

        $isEarlyDeparture = $unit->status === UnitStatus::CheckedIn && $newDeparture->lt($oldDeparture) && $newArrival->equalTo($oldArrival);
        $fee = null;

        if ($isEarlyDeparture) {
            $policy = $this->policy($unit, SystemTransactionCode::EarlyDepartureRevenue);

            if ($policy === null) {
                return UnitDatesPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_POLICY_NOT_FOUND));
            }

            $fee = (string) $this->policyChargeCalculator->calculate($policy, $unit, $newDeparture)->amount->getAmount();
        }

        return new UnitDatesPreviewData(
            previousTotal: (string) $previous->getAmount(),
            newTotal: (string) $total->getAmount(),
            difference: (string) $total->minus($previous)->getAmount(),
            nights: (int) $newArrival->diffInDays($newDeparture),
            isEarlyDeparture: $isEarlyDeparture,
            fee: $fee,
            restricted: $added !== [] && $this->restrictionCheck->violations($ratePlan->id, $roomType->id, $newArrival, $newDeparture) !== [],
            error: null,
        );
    }

    private function policy(ReservationUnit $unit, SystemTransactionCode $code): ?RoomRevenuePolicy
    {
        return RoomRevenuePolicy::query()->where('property_id', $unit->property_id)->where('code', $code)->first();
    }

    private function currency(ReservationUnit $unit): string
    {
        return $unit->property()->firstOrFail()->settings->currency->getCurrencyCode();
    }

    /**
     * @return list<int>
     */
    private function childAges(ReservationUnit $unit): array
    {
        return array_values($unit->childAges()->pluck('age')->map(fn (mixed $age): int => (int) $age)->all());
    }

    /**
     * @param  iterable<UnitNight>  $nights
     */
    private function total(iterable $nights, string $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($nights as $night) {
            $total = $total->plus($night->amount);
        }

        return $total;
    }

    /**
     * The nights of the new stay that the unit does not hold now, as runs of consecutive dates.
     *
     * @return list<non-empty-list<CarbonImmutable>>
     */
    private function addedRuns(CarbonImmutable $newArrival, CarbonImmutable $newDeparture, CarbonImmutable $oldArrival, CarbonImmutable $oldDeparture): array
    {
        $runs = [];

        for ($night = $newArrival; $night->lt($newDeparture); $night = $night->addDay()) {
            if ($night->gte($oldArrival) && $night->lt($oldDeparture)) {
                continue;
            }

            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][array_key_last($runs[$last])]->addDay()->equalTo($night)) {
                $runs[$last][] = $night;
            } else {
                $runs[] = [$night];
            }
        }

        return $runs;
    }
}

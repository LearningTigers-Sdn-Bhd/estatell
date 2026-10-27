<?php

namespace App\Services\FrontDesk;

use App\Actions\FrontDesk\GroupCheckOutRow;
use App\Actions\FrontDesk\OnePayment;
use App\Actions\FrontDesk\UnitStayResult;
use App\Data\Payments\PaymentMethodOptionData;
use App\Data\Reservations\GroupCheckOutFolioData;
use App\Data\Reservations\GroupCheckOutMasterData;
use App\Data\Reservations\GroupCheckOutPreviewData;
use App\Data\Reservations\GroupCheckOutRowData;
use App\Data\Reservations\GroupCheckOutSummaryData;
use App\Data\Reservations\ReservationUnitData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PermissionEnum;
use App\Enums\StayType;
use App\Enums\UnitAction;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\FolioReopenRule;
use App\Services\Folio\FolioStatement;
use App\Services\Folio\IncomingScheduledCharges;
use App\Services\Folio\MoveChargesPreview;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Payments\PaymentPurposeChoices;
use App\Services\Platform\Impersonation;
use App\Services\Reservations\UnitActionMenu;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Reads the group check-out page of a reservation and writes nothing: each room classified as the Rows table of the
 * doc says, its balance and the settle choices by balance (prefilled), the Master line and the summary of the choices
 * sent. `kind()` is the one rule for which rooms can be touched; `CheckOutGroup` uses it too.
 * See docs/18-front-desk/05-group-check-out.md.
 */
class GroupCheckOutPreview
{
    public const string KIND_READY = 'ready';

    public const string KIND_DEPARTS_LATER = 'departs_later';

    public const string KIND_CHARGED_ELSEWHERE = 'charged_elsewhere';

    public const string KIND_OUT = 'out';

    public const string KIND_NOT_ARRIVED = 'not_arrived';

    public const string KIND_CANCELLED = 'cancelled';

    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private CheckOutSettlement $checkOutSettlement,
        private FolioBalance $folioBalance,
        private IncomingScheduledCharges $incomingScheduledCharges,
        private MoveChargesPreview $moveChargesPreview,
        private FolioReopenRule $folioReopenRule,
        private EarlyCheckOutRule $earlyCheckOutRule,
        private Impersonation $impersonation,
        private StayDay $stayDay,
        private PendingNightQuote $pendingNightQuote,
        private AuditPendingNotice $auditPendingNotice,
        private PaymentPurposeChoices $paymentPurposeChoices,
        private FolioStatement $folioStatement,
        private UnitActionMenu $unitActionMenu,
    ) {}

    public static function selectable(string $kind): bool
    {
        return in_array($kind, [self::KIND_READY, self::KIND_OUT, self::KIND_CANCELLED], true);
    }

    /**
     * What a room is for the group check-out. A CheckedIn room that departs after the business date, or whose folio
     * still receives another room's Scheduled charges, or a Confirmed room, cannot be touched.
     */
    public function kind(ReservationUnit $unit, CarbonImmutable $businessDate): string
    {
        return match ($unit->status) {
            UnitStatus::Confirmed => self::KIND_NOT_ARRIVED,
            UnitStatus::CheckedOut => self::KIND_OUT,
            UnitStatus::Cancelled, UnitStatus::NoShow => self::KIND_CANCELLED,
            UnitStatus::CheckedIn => match (true) {
                $unit->departure_date->toDateString() > $this->stayDayOf($unit, $businessDate) => self::KIND_DEPARTS_LATER,
                $this->checkOutSettlement->incomingScheduled($unit, $this->checkOutSettlement->folios($unit)) !== [] => self::KIND_CHARGED_ELSEWHERE,
                default => self::KIND_READY,
            },
        };
    }

    /**
     * The day a room departs on or before: the stay day while the night audit is pending, otherwise the business date.
     * A day use room keeps the business date.
     */
    private function stayDayOf(ReservationUnit $unit, CarbonImmutable $businessDate): string
    {
        return $unit->stay_type === StayType::DayUse ? $businessDate->toDateString() : $this->stayDay->forCheckOut($unit->property, $businessDate)->toDateString();
    }

    /**
     * @param  list<GroupCheckOutRow>|null  $choices  The choices to total; null totals the prefilled ones.
     */
    public function forReservation(Reservation $reservation, User $user, ?array $choices = null, ?OnePayment $onePayment = null, bool $settleMaster = false): GroupCheckOutPreviewData
    {
        $property = $reservation->property()->with('settings')->firstOrFail();
        $businessDate = $this->currentBusinessDate->date($property);
        $methods = $this->methods($reservation);
        $summary = new GroupCheckOutSummaryData('0.00', '0.00', '0.00', 0, 0, false, null, null);

        if ($businessDate === null) {
            return new GroupCheckOutPreviewData([], [], [], null, $summary, $methods['data'], __('This property has no open business date yet.'));
        }

        $master = $this->openMaster($reservation);
        $timeZone = $property->settings->time_zone ?? config('app.timezone');
        $units = $reservation->units()->with(['guests.guest', 'roomType'])->orderBy('id')->get();
        $names = $this->incomingScheduledCharges->unitNames(array_values(array_map(intval(...), $units->modelKeys())));
        $rows = [];
        $nothing = ['out' => [], 'cancelled' => []];

        foreach ($units as $unit) {
            $row = $this->row($unit, $user, $businessDate, $master, $timeZone, $names, $methods, $nothing);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (GroupCheckOutRowData $a, GroupCheckOutRowData $b): int => [$this->order($a->group), $a->unitId] <=> [$this->order($b->group), $b->unitId]);

        $masterData = $master === null ? null : $this->master($master, $reservation, $user);

        return new GroupCheckOutPreviewData(
            $rows,
            $nothing['out'],
            $nothing['cancelled'],
            $masterData,
            $this->summary($rows, $choices, $onePayment, $settleMaster, $masterData),
            $methods['data'],
            null,
            $this->auditPendingNotice->block($property, collect($rows)->contains(fn (GroupCheckOutRowData $row): bool => $row->pendingLines !== [])
                ? __('Last night (:date) is added to the bill of each room checking out now. The night audit will skip it.', ['date' => $businessDate->format('j M')])
                : null),
            $rows === [] ? $this->openFolios($reservation, $property, $user) : [],
        );
    }

    /**
     * The Master line of the reservation as it stands now, or null without an Open Master folio. The success state uses
     * it to offer **Settle to City ledger** only while the Master can still be settled.
     */
    public function masterLine(Reservation $reservation, User $user): ?GroupCheckOutMasterData
    {
        $master = $this->openMaster($reservation);

        return $master === null ? null : $this->master($master, $reservation, $user);
    }

    /**
     * Whether **Check out all** is offered: 2 or more units, and one is CheckedIn or holds money on a unit folio.
     *
     * @param  EloquentCollection<int, ReservationUnit>  $units
     */
    public function isOffered(Collection $units): bool
    {
        if ($units->count() < 2) {
            return false;
        }

        if ($units->contains(fn (ReservationUnit $unit): bool => $unit->status === UnitStatus::CheckedIn)) {
            return true;
        }

        return Folio::query()
            ->whereIn('reservation_unit_id', $units->modelKeys())
            ->where('type', FolioType::Unit)
            ->where('status', '!=', FolioStatus::Voided)
            ->whereIn('id', FolioLine::query()->select('folio_id')->groupBy('folio_id')->havingRaw('SUM(amount) <> 0'))
            ->exists();
    }

    /**
     * The Open Master and Third party folios of the reservation that hold money (a balance above or below 0), the
     * Master first. Folios at 0 are left out: nothing to settle. Each says what the user may do with it here.
     *
     * @return list<GroupCheckOutFolioData>
     */
    private function openFolios(Reservation $reservation, Property $property, User $user): array
    {
        $canRecord = $user->can(PermissionEnum::PaymentRecord->value);
        $canSettle = $user->can(PermissionEnum::CityLedgerInvoice->value);

        return array_values(Folio::query()
            ->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->whereIn('type', [FolioType::Master, FolioType::ThirdParty])
            ->where('status', FolioStatus::Open)
            ->with(['payerCompany', 'payerGuest'])
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Folio $folio): int => $folio->type === FolioType::Master ? 0 : 1)
            ->map(function (Folio $folio) use ($reservation, $property, $canRecord, $canSettle): ?GroupCheckOutFolioData {
                $balance = $this->folioBalance->forFolio($folio);

                if (BigDecimal::of($balance)->isZero()) {
                    return null;
                }

                $company = $folio->payerCompany;
                $settles = $canSettle && $company !== null && $company->direct_bill_enabled && BigDecimal::of($balance)->isPositive();
                $purposes = $canRecord ? $this->paymentPurposeChoices->for($reservation, $folio, null) : [];

                return new GroupCheckOutFolioData(
                    (string) $folio->id,
                    $this->moveChargesPreview->folioLabel($folio),
                    $balance,
                    $company?->name,
                    $purposes !== [],
                    $settles,
                    null,
                    array_map(fn (PaymentPurpose $purpose): array => ['value' => $purpose->value, 'label' => $purpose->label()], $purposes),
                    ($purposes[0] ?? null)?->value,
                    $settles ? $this->folioStatement->settleCreditWarning($folio) : null,
                    route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]),
                );
            })
            ->filter()
            ->all());
    }

    private function openMaster(Reservation $reservation): ?Folio
    {
        return Folio::query()->where('property_id', $reservation->property_id)->where('reservation_id', $reservation->id)
            ->where('type', FolioType::Master)->where('status', FolioStatus::Open)->oldest('id')->first();
    }

    private function order(string $group): int
    {
        return match ($group) {
            'ready' => 0,
            'money' => 1,
            'later' => 2,
            default => 3,
        };
    }

    /**
     * @param  array<int, string>  $names
     * @param  array{data: list<PaymentMethodOptionData>, first: int|null}  $methods
     * @param  array{out: list<string>, cancelled: list<string>}  $nothing  Gets the name of a room that is out or cancelled with nothing to settle, which is not a row.
     */
    private function row(ReservationUnit $unit, User $user, CarbonImmutable $businessDate, ?Folio $master, string $timeZone, array $names, array $methods, array &$nothing): ?GroupCheckOutRowData
    {
        $kind = $this->kind($unit, $businessDate);
        $folios = $this->checkOutSettlement->folios($unit);
        $pendingLines = $kind === self::KIND_READY ? $this->pendingNightQuote->lines($unit, $businessDate) : [];
        $pending = $this->pendingNightQuote->total($pendingLines);
        $balance = $folios->reduce(fn (BigDecimal $sum, Folio $folio): BigDecimal => $sum->plus($this->folioBalance->forFolio($folio)), $pending);
        $hasMoney = ! $balance->isZero();

        if (! $hasMoney && ($kind === self::KIND_OUT || $kind === self::KIND_CANCELLED)) {
            $nothing[$kind === self::KIND_OUT ? 'out' : 'cancelled'][] = $names[$unit->id] ?? $unit->roomType->name;

            return null;
        }

        $selectable = self::selectable($kind);
        $openFolio = $folios->first(fn (Folio $folio): bool => $folio->status === FolioStatus::Open);
        $closedFolio = $folios->first(fn (Folio $folio): bool => $folio->status === FolioStatus::Closed);
        $hasOpenFolio = $openFolio !== null || ($pendingLines !== [] && $closedFolio === null);
        $folioClosed = $selectable && $hasMoney && ! $hasOpenFolio;
        $actionable = $selectable && $hasOpenFolio;
        $incoming = $kind === self::KIND_CHARGED_ELSEWHERE ? $this->checkOutSettlement->incomingScheduled($unit, $folios) : [];
        $held = $this->checkOutSettlement->heldPayments($folios);
        $heldTotal = $held->reduce(fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($this->checkOutSettlement->refundable($payment)), BigDecimal::zero());
        $refundPool = $heldTotal;
        $credit = $balance->isNegative() ? $balance->negated() : BigDecimal::zero();
        $moveAmount = $this->checkOutSettlement->movableTotal($folios)->plus($pending);
        $canMove = $actionable && $balance->isPositive() && $master !== null && $user->can(PermissionEnum::FolioTransfer->value)
            && ($this->checkOutSettlement->movableLines($folios)->isNotEmpty() || $pendingLines !== [])
            && ! $this->checkOutSettlement->holdsUnrefundable($folios)
            && ($held->isEmpty() || $this->impersonation->mayRefund($user));
        $choices = array_values(array_filter([
            $actionable && $balance->isPositive() && $user->can(PermissionEnum::PaymentRecord->value) ? 'collect' : null,
            $canMove ? 'move' : null,
            $actionable && $balance->isNegative() && $refundPool->isPositive() && $this->impersonation->mayRefund($user) ? 'refund' : null,
        ]));
        $earlyRefusal = $kind === self::KIND_DEPARTS_LATER ? $this->earlyCheckOutRule->refusal($unit, $businessDate->toDateString()) : null;
        $rowAction = $kind === self::KIND_DEPARTS_LATER
            ? collect($this->unitActionMenu->for($user, $unit))->first(fn ($item): bool => ! $item->disabled && in_array($item->key, [UnitAction::CheckOutAsDayUse, UnitAction::CheckOut], true))
            : null;
        $group = match ($kind) {
            self::KIND_READY => 'ready',
            self::KIND_OUT, self::KIND_CANCELLED => 'money',
            self::KIND_DEPARTS_LATER => 'later',
            default => 'blocked',
        };
        $guest = $unit->guests->firstWhere('is_primary', true)?->guest->full_name;
        $lastMethod = Payment::query()->whereIn('folio_id', $folios->modelKeys())->whereNull('refund_of_id')->latest('id')->value('payment_method_id');
        $methodIds = array_map(fn (PaymentMethodOptionData $method): int => $method->id, $methods['data']);
        $moveNote = $canMove
            ? ($heldTotal->isPositive()
                ? __('Moves :moved · refunds :refunded :what', ['moved' => (string) $moveAmount, 'refunded' => (string) $heldTotal->toScale(2), 'what' => $held->every(fn (Payment $payment): bool => $payment->purpose === PaymentPurpose::SecurityDeposit) ? __('deposit') : __('in payments')])
                : __('Moves :moved', ['moved' => (string) $moveAmount]))
            : null;

        return new GroupCheckOutRowData(
            $unit->id,
            $names[$unit->id] ?? $unit->roomType->name,
            $guest,
            $unit->status->value,
            $group,
            $selectable && ($kind === self::KIND_READY || ($hasMoney && $hasOpenFolio)),
            $selectable,
            match ($kind) {
                self::KIND_DEPARTS_LATER => match ($earlyRefusal) {
                    UnitStayResult::REFUSAL_ARRIVED_TODAY => __('Arrived today.'),
                    UnitStayResult::REFUSAL_NIGHT_AUDIT_PENDING => __('The night audit for :date has not run. Run it first, so the night of :date is charged.', ['date' => $businessDate->format('j M')]),
                    default => __('Departs :date', ['date' => $unit->departure_date->format('j M')]),
                },
                self::KIND_CHARGED_ELSEWHERE => IncomingScheduledCharges::message($incoming),
                self::KIND_NOT_ARRIVED => __('Not checked in: Cancel or mark no-show.'),
                self::KIND_READY => $this->pendingNightQuote->isOverdue($unit, $businessDate)
                    ? __('Due to leave yesterday (:date). If they stayed last night, check this room out on its own.', ['date' => $businessDate->format('j M')])
                    : ($folioClosed ? __('Folio closed: reopen it to settle.') : null),
                default => $folioClosed ? __('Folio closed: reopen it to settle.') : null,
            },
            $unit->departure_date->toDateString(),
            $kind === self::KIND_OUT ? $unit->checked_out_at?->setTimezone($timeZone)->toDateString() : null,
            (string) $balance->toScale(2),
            (string) ($balance->isPositive() ? $balance : BigDecimal::zero())->toScale(2),
            (string) ($credit->isLessThan($refundPool) ? $credit : $refundPool)->toScale(2),
            (string) $moveAmount,
            (string) $heldTotal->toScale(2),
            $moveNote,
            $choices,
            $choices[0] ?? null,
            $lastMethod !== null && in_array((int) $lastMethod, $methodIds, true) ? (int) $lastMethod : $methods['first'],
            $incoming,
            $folioClosed,
            $folioClosed ? $closedFolio?->id : null,
            $folioClosed && $closedFolio !== null && $this->folioReopenRule->allows($user, $closedFolio),
            $kind === self::KIND_CHARGED_ELSEWHERE && $user->can(PermissionEnum::FolioTransfer->value),
            $rowAction !== null ? $this->unitData($unit, $user) : null,
            $pendingLines,
            (string) $pending->toScale(2),
            $rowAction,
        );
    }

    /**
     * The unit as the reservation page sends it, for the early or day use check-out Dialog.
     */
    private function unitData(ReservationUnit $unit, User $user): ReservationUnitData
    {
        $unit->load(['ratePlan', 'childAges', 'nights' => fn ($nights) => $nights->orderBy('date'), 'nights.room', 'nights.bed', 'nights.ratePlan']);

        return ReservationUnitData::fromModel($unit, $user->can(PermissionEnum::GuestsView->value), null, null);
    }

    private function master(Folio $master, Reservation $reservation, User $user): GroupCheckOutMasterData
    {
        $company = $master->payer_company_id === null ? null : Company::query()->where('property_id', $master->property_id)->find($master->payer_company_id);

        return new GroupCheckOutMasterData(
            $master->id,
            $this->moveChargesPreview->folioLabel($master),
            $this->folioBalance->forFolio($master),
            $company->name ?? $reservation->group_name ?? $this->moveChargesPreview->folioLabel($master),
            $company?->name,
            $company !== null && $company->direct_bill_enabled && $user->can(PermissionEnum::CityLedgerInvoice->value),
        );
    }

    /**
     * @param  list<GroupCheckOutRowData>  $rows
     * @param  list<GroupCheckOutRow>|null  $choices
     */
    private function summary(array $rows, ?array $choices, ?OnePayment $onePayment, bool $settleMaster, ?GroupCheckOutMasterData $master): GroupCheckOutSummaryData
    {
        $byUnit = $choices === null ? null : collect($choices)->keyBy('unitId');
        $collect = BigDecimal::zero();
        $refund = BigDecimal::zero();
        $move = BigDecimal::zero();
        $pool = BigDecimal::zero();
        $out = 0;
        $settled = 0;

        foreach ($rows as $row) {
            if (! $row->selectable) {
                continue;
            }

            if ($byUnit === null) {
                $choice = $row->ticked ? $row->defaultChoice : null;
                $picked = $row->ticked;
            } else {
                $given = $byUnit->get($row->unitId);
                $picked = $given !== null;
                $choice = match (true) {
                    $given?->addToMaster => 'move',
                    $given?->collect !== null => 'collect',
                    $given?->refundAmount !== null => 'refund',
                    default => null,
                };
            }

            if (! $picked) {
                continue;
            }

            if ($row->status === UnitStatus::CheckedIn->value) {
                $out++;
            } elseif ($choice !== null) {
                $settled++;
            }

            if ($choice === 'move') {
                $move = $move->plus($row->moveAmount);
                $refund = $refund->plus($row->refundOnMove);
            } elseif ($choice === 'collect') {
                $amount = $byUnit?->get($row->unitId)->collect->amount ?? $row->collectAmount;

                if ($onePayment === null) {
                    $collect = $collect->plus($amount);
                } else {
                    $pool = $pool->plus($row->moveAmount);
                    $refund = $refund->plus($row->refundOnMove);
                }
            } elseif ($choice === 'refund') {
                $refund = $refund->plus($byUnit?->get($row->unitId)->refundAmount ?? $row->refundAmount);
            }
        }

        if ($onePayment !== null) {
            $collect = $collect->plus($pool);
        }

        return new GroupCheckOutSummaryData(
            (string) $collect->toScale(2),
            (string) $refund->toScale(2),
            (string) $move->toScale(2),
            $out,
            $settled,
            $settleMaster,
            $master === null ? null : (string) BigDecimal::of($master->balance)->plus($move)->toScale(2),
            $onePayment === null ? null : (string) $pool->toScale(2),
        );
    }

    /**
     * @return array{data: list<PaymentMethodOptionData>, first: int|null}
     */
    private function methods(Reservation $reservation): array
    {
        $data = array_values(PaymentMethod::query()->where('property_id', $reservation->property_id)->active()
            ->whereNotIn('type', [PaymentMethodType::OtaCollected, PaymentMethodType::DirectBill])
            ->ordered()->get()
            ->map(PaymentMethodOptionData::fromModel(...))->all());

        return ['data' => $data, 'first' => $data[0]->id ?? null];
    }
}

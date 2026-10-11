<?php

namespace App\Services\FrontDesk;

use App\Actions\FrontDesk\GroupCheckOutRow;
use App\Actions\FrontDesk\OnePayment;
use App\Data\Payments\PaymentMethodOptionData;
use App\Data\Reservations\GroupCheckOutMasterData;
use App\Data\Reservations\GroupCheckOutPreviewData;
use App\Data\Reservations\GroupCheckOutRowData;
use App\Data\Reservations\GroupCheckOutSummaryData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PermissionEnum;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\FolioReopenRule;
use App\Services\Folio\IncomingScheduledCharges;
use App\Services\Folio\MoveChargesPreview;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
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
                $unit->departure_date->toDateString() > $businessDate->toDateString() => self::KIND_DEPARTS_LATER,
                $this->checkOutSettlement->incomingScheduled($unit, $this->checkOutSettlement->folios($unit)) !== [] => self::KIND_CHARGED_ELSEWHERE,
                default => self::KIND_READY,
            },
        };
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
            return new GroupCheckOutPreviewData([], null, $summary, $methods['data'], __('This property has no open business date yet.'));
        }

        $master = $this->openMaster($reservation);
        $units = $reservation->units()->with(['guests.guest', 'roomType'])->orderBy('id')->get();
        $names = $this->incomingScheduledCharges->unitNames($units->modelKeys());
        $rows = [];

        foreach ($units as $unit) {
            $row = $this->row($unit, $user, $businessDate, $master, $names, $methods);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (GroupCheckOutRowData $a, GroupCheckOutRowData $b): int => [$this->order($a->group), $a->unitId] <=> [$this->order($b->group), $b->unitId]);

        $masterData = $master === null ? null : $this->master($master, $user);

        return new GroupCheckOutPreviewData(
            $rows,
            $masterData,
            $this->summary($rows, $choices, $onePayment, $settleMaster, $masterData),
            $methods['data'],
            null,
        );
    }

    /**
     * The Master line of the reservation as it stands now, or null without an Open Master folio. The success state uses
     * it to offer **Settle to City ledger** only while the Master can still be settled.
     */
    public function masterLine(Reservation $reservation, User $user): ?GroupCheckOutMasterData
    {
        $master = $this->openMaster($reservation);

        return $master === null ? null : $this->master($master, $user);
    }

    /**
     * Whether **Check out all** is offered: 2 or more units, and one is CheckedIn or holds money on a unit folio.
     *
     * @param  Collection<int, ReservationUnit>  $units
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

    private function openMaster(Reservation $reservation): ?Folio
    {
        return Folio::query()->where('property_id', $reservation->property_id)->where('reservation_id', $reservation->id)
            ->where('type', FolioType::Master)->where('status', FolioStatus::Open)->oldest('id')->first();
    }

    private function order(string $group): int
    {
        return match ($group) {
            'main' => 0,
            'out' => 1,
            'not_arrived' => 2,
            default => 3,
        };
    }

    /**
     * @param  array<int, string>  $names
     * @param  array{data: list<PaymentMethodOptionData>, first: int|null}  $methods
     */
    private function row(ReservationUnit $unit, User $user, CarbonImmutable $businessDate, ?Folio $master, array $names, array $methods): ?GroupCheckOutRowData
    {
        $kind = $this->kind($unit, $businessDate);
        $folios = $this->checkOutSettlement->folios($unit);
        $balance = $folios->reduce(fn (BigDecimal $sum, Folio $folio): BigDecimal => $sum->plus($this->folioBalance->forFolio($folio)), BigDecimal::zero());
        $hasMoney = ! $balance->isZero();

        if ($kind === self::KIND_CANCELLED && ! $hasMoney) {
            return null;
        }

        $selectable = self::selectable($kind);
        $openFolio = $folios->first(fn (Folio $folio): bool => $folio->status === FolioStatus::Open);
        $closedFolio = $folios->first(fn (Folio $folio): bool => $folio->status === FolioStatus::Closed);
        $folioClosed = $selectable && $hasMoney && $openFolio === null;
        $actionable = $selectable && $openFolio !== null;
        $incoming = $kind === self::KIND_CHARGED_ELSEWHERE ? $this->checkOutSettlement->incomingScheduled($unit, $folios) : [];
        $held = $this->checkOutSettlement->heldPayments($folios);
        $heldTotal = $held->reduce(fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($this->checkOutSettlement->refundable($payment)), BigDecimal::zero());
        $refundPool = $unit->status === UnitStatus::CheckedIn
            ? $this->checkOutSettlement->heldDeposits($folios)->reduce(fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($this->checkOutSettlement->refundable($payment)), BigDecimal::zero())
            : $heldTotal;
        $credit = $balance->isNegative() ? $balance->negated() : BigDecimal::zero();
        $moveAmount = $this->checkOutSettlement->movableTotal($folios);
        $canMove = $actionable && $balance->isPositive() && $master !== null && $user->can(PermissionEnum::FolioTransfer->value)
            && $this->checkOutSettlement->movableLines($folios)->isNotEmpty()
            && ! $this->checkOutSettlement->holdsUnrefundable($folios)
            && ($held->isEmpty() || $user->can(PermissionEnum::PaymentRefund->value));
        $choices = array_values(array_filter([
            $actionable && $balance->isPositive() && $user->can(PermissionEnum::PaymentRecord->value) ? 'collect' : null,
            $canMove ? 'move' : null,
            $actionable && $balance->isNegative() && $refundPool->isPositive() && $user->can(PermissionEnum::PaymentRefund->value) ? 'refund' : null,
        ]));
        $group = match ($kind) {
            self::KIND_OUT => 'out',
            self::KIND_CANCELLED => 'cancelled',
            self::KIND_NOT_ARRIVED => 'not_arrived',
            default => 'main',
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
            $selectable && ($kind === self::KIND_READY || ($hasMoney && $openFolio !== null)),
            $selectable,
            match ($kind) {
                self::KIND_DEPARTS_LATER => __('Departs :date: use Check out for an early check-out.', ['date' => $unit->departure_date->format('j M')]),
                self::KIND_CHARGED_ELSEWHERE => IncomingScheduledCharges::message($incoming),
                self::KIND_NOT_ARRIVED => __('Not checked in: Cancel or mark no-show.'),
                default => $folioClosed ? __('Folio closed: reopen it to settle.') : null,
            },
            $unit->departure_date->toDateString(),
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
        );
    }

    private function master(Folio $master, User $user): GroupCheckOutMasterData
    {
        $company = $master->payer_company_id === null ? null : Company::query()->where('property_id', $master->property_id)->find($master->payer_company_id);

        return new GroupCheckOutMasterData(
            $master->id,
            $this->moveChargesPreview->folioLabel($master),
            $this->folioBalance->forFolio($master),
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
                $amount = $byUnit?->get($row->unitId)?->collect?->amount ?? $row->collectAmount;

                if ($onePayment === null) {
                    $collect = $collect->plus($amount);
                } else {
                    $pool = $pool->plus($row->moveAmount);
                    $refund = $refund->plus($row->refundOnMove);
                }
            } elseif ($choice === 'refund') {
                $refund = $refund->plus($byUnit?->get($row->unitId)?->refundAmount ?? $row->refundAmount);
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
        $data = PaymentMethod::query()->where('property_id', $reservation->property_id)->active()
            ->whereNotIn('type', [PaymentMethodType::OtaCollected, PaymentMethodType::DirectBill])
            ->ordered()->get()
            ->map(PaymentMethodOptionData::fromModel(...))->all();

        return ['data' => $data, 'first' => $data[0]->id ?? null];
    }
}

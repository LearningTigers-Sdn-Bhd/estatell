<?php

namespace App\Actions\FrontDesk;

use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\TransferLines;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\CheckOutCollectData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\FrontDesk\CheckOutSettlement;
use App\Services\FrontDesk\GroupCheckOutPreview;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckOutGroup
{
    public function __construct(
        private CheckOutAndRefund $checkOutAndRefund,
        private TransferLines $transferLines,
        private RecordPayment $recordPayment,
        private RefundPayment $refundPayment,
        private SettleToCityLedger $settleToCityLedger,
        private CurrentBusinessDate $currentBusinessDate,
        private PostPendingNight $postPendingNight,
        private CheckOutSettlement $checkOutSettlement,
        private GroupCheckOutPreview $groupCheckOutPreview,
        private FolioBalance $folioBalance,
    ) {}

    /**
     * Check out the rooms of a group and settle their money in one save, all or nothing. It runs three steps in one
     * transaction. (1) The moves: each Add to Master row, and each Collect row when One payment for all is set, goes
     * through `TransferLines` (to the Master folio or to the paying room's folio), and in the same step the payments
     * the moved folio holds are refunded oldest first through `RefundPayment`, because a payment never moves; then
     * the one payment is recorded on the paying room's folio for the charges moved there. (2) Each row: a CheckedIn
     * room goes through `CheckOutAndRefund` with its collect and refund data, and a CheckedOut, Cancelled or NoShow
     * room only gets its payment, refund or move (`CheckOutUnit` never runs twice) and must end with its folios at 0.
     * (3) With `$settleMaster`, `SettleToCityLedger` on the Master folio after every room is out.
     * `CheckOutAndRefund` opens its own transaction, which is a savepoint here and rolls back only itself, so every
     * step's result is checked and the first refusal rolls the whole save back and comes back tied to its room.
     * A row for a room that departs later, that receives another room's Scheduled charges or that has not arrived is
     * refused (`row_not_available`) and never touched. The payments and refunds use `{uuid}:{unit}:collect`, `{uuid}:all`
     * and `{uuid}:{payment id}` keys, so a repeat of the same save records nothing twice. Every unit keeps its own log
     * entries; one `reservation.group_checked_out` entry holds the totals. Permissions are checked by the route and
     * the Form Request. See docs/18-front-desk/05-group-check-out.md.
     *
     * @param  list<GroupCheckOutRow>  $rows
     */
    public function __invoke(User $actor, Reservation $reservation, array $rows, ?OnePayment $onePayment, bool $settleMaster): CheckOutGroupResult
    {
        DB::beginTransaction();

        try {
            $result = $this->run($actor, $reservation, $rows, $onePayment, $settleMaster);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }

    /**
     * @param  list<GroupCheckOutRow>  $rows
     */
    private function run(User $actor, Reservation $reservation, array $rows, ?OnePayment $onePayment, bool $settleMaster): CheckOutGroupResult
    {
        $businessDate = $this->currentBusinessDate->date($reservation->property);

        if ($businessDate === null) {
            return CheckOutGroupResult::refused(CheckOutGroupResult::REFUSAL_NO_BUSINESS_DATE);
        }

        $ids = array_map(fn (GroupCheckOutRow $row): int => $row->unitId, $rows);

        if (count(array_unique($ids)) !== count($ids)) {
            return CheckOutGroupResult::refused(CheckOutGroupResult::REFUSAL_DUPLICATE_ROW);
        }

        $units = $reservation->units()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($rows as $row) {
            if (! $units->has($row->unitId)) {
                return CheckOutGroupResult::refused(CheckOutGroupResult::REFUSAL_UNIT_NOT_FOUND, $row->unitId);
            }

            if (($row->addToMaster && ($row->collect !== null || $row->refundAmount !== null)) || ($row->collect !== null && $row->refundAmount !== null)) {
                return CheckOutGroupResult::refused(CheckOutGroupResult::REFUSAL_INVALID_CHOICE, $row->unitId);
            }

            if (! GroupCheckOutPreview::selectable($this->groupCheckOutPreview->kind($units[$row->unitId], $businessDate))) {
                return CheckOutGroupResult::refused(CheckOutGroupResult::REFUSAL_ROW_NOT_AVAILABLE, $row->unitId);
            }
        }

        foreach ($rows as $row) {
            try {
                ($this->postPendingNight)($actor, $units[$row->unitId]);
            } catch (UnitStayRefused $refused) {
                return CheckOutGroupResult::refused((string) $refused->result->refusal, $row->unitId);
            }
        }

        $master = $this->masterFolio($reservation);
        $payerRow = $onePayment === null ? null : collect($rows)->first(fn (GroupCheckOutRow $row): bool => $row->unitId === $onePayment->payerUnitId);

        if ($onePayment !== null && ($payerRow === null || $payerRow->addToMaster || $payerRow->refundAmount !== null || ! in_array($units[$payerRow->unitId]->status, [UnitStatus::CheckedIn, UnitStatus::CheckedOut], true))) {
            return CheckOutGroupResult::refused(CheckOutGroupResult::REFUSAL_INVALID_PAYER, $onePayment->payerUnitId);
        }

        $payerFolio = $onePayment === null ? null : $this->openUnitFolio($units[$onePayment->payerUnitId]);
        $payerOwn = $payerRow?->collect === null ? BigDecimal::zero() : $this->ownBalance($units[$payerRow->unitId]);
        $pooled = fn (GroupCheckOutRow $row): bool => $onePayment !== null && $row->collect !== null && $row->unitId !== $onePayment->payerUnitId;
        $totals = ['collected' => BigDecimal::zero(), 'refunded' => BigDecimal::zero(), 'moved' => BigDecimal::zero()];
        $pool = $payerOwn->isPositive() ? $payerOwn : BigDecimal::zero();
        $handled = [];

        foreach ($rows as $row) {
            if (! $row->addToMaster && ! $pooled($row)) {
                continue;
            }

            $moved = $this->move($actor, $units[$row->unitId], $row, $row->addToMaster ? $master : $payerFolio, $row->addToMaster ? CheckOutGroupResult::REFUSAL_NO_MASTER_FOLIO : CheckOutGroupResult::REFUSAL_INVALID_PAYER);

            if ($moved['refusal'] !== null) {
                return CheckOutGroupResult::refused($moved['refusal'], $row->unitId);
            }

            $handled[$row->unitId] = true;
            $totals['refunded'] = $totals['refunded']->plus($moved['refunded']);

            if ($row->addToMaster) {
                $totals['moved'] = $totals['moved']->plus($moved['moved']);
            } else {
                $pool = $pool->plus($moved['moved']);
            }
        }

        if ($onePayment !== null) {
            $refusal = $this->takeOnePayment($actor, $reservation, $onePayment, $payerFolio, $pool, $totals);

            if ($refusal !== null) {
                return CheckOutGroupResult::refused($refusal, $onePayment->payerUnitId);
            }
        }

        $checkedOut = [];
        $settled = [];

        foreach ($rows as $row) {
            $unit = $units[$row->unitId]->refresh();
            $moved = isset($handled[$row->unitId]);
            $collect = $moved || $onePayment !== null ? null : $row->collect;
            $refundAmount = $moved ? null : $row->refundAmount;

            if ($unit->status === UnitStatus::CheckedIn) {
                $result = ($this->checkOutAndRefund)($actor, $unit, $refundAmount, $collect, $row->idempotencyKey);

                if ($result->isRefused()) {
                    return CheckOutGroupResult::refused((string) $result->refusal, $row->unitId, $result->rooms);
                }

                $checkedOut[] = $unit->id;
                $totals['collected'] = $totals['collected']->plus($collect === null ? '0' : $collect->amount);
                $totals['refunded'] = $totals['refunded']->plus($refundAmount ?? '0');

                continue;
            }

            $touched = $moved || $collect !== null || $refundAmount !== null || ($onePayment !== null && $row->collect !== null);
            $refusal = $this->settleMoney($actor, $reservation, $unit, $row, $collect, $refundAmount, $touched, $totals);

            if ($refusal !== null) {
                return CheckOutGroupResult::refused($refusal, $row->unitId);
            }

            if ($touched) {
                $settled[] = $unit->id;
            }
        }

        $invoice = null;

        if ($settleMaster) {
            if ($master === null) {
                return CheckOutGroupResult::refused(CheckOutGroupResult::REFUSAL_NO_MASTER_FOLIO);
            }

            $settledMaster = ($this->settleToCityLedger)($actor, $master);

            if ($settledMaster->isRefused()) {
                return CheckOutGroupResult::refused((string) $settledMaster->refusal, null, $settledMaster->rooms);
            }

            $invoice = $settledMaster->invoice;
        }

        $result = CheckOutGroupResult::done(
            $checkedOut,
            $settled,
            (string) $totals['collected']->toScale(2),
            (string) $totals['refunded']->toScale(2),
            (string) $totals['moved']->toScale(2),
            $invoice,
            $this->paymentIds($rows, $onePayment),
        );

        $this->log($actor, $reservation, $result, $onePayment !== null, $businessDate->toDateString());

        return $result;
    }

    /**
     * Move every open charge of the room to the target through `TransferLines`, then refund the payments its folios
     * hold. A room with no open charge and a balance of 0 has nothing to move (a repeat of the same save), and one with
     * a balance and no charge is refused. A payment that cannot be refunded (a City ledger line) refuses the row before
     * anything is written. Returns the amount moved and refunded.
     *
     * @return array{refusal: string|null, moved: BigDecimal, refunded: BigDecimal}
     */
    private function move(User $actor, ReservationUnit $unit, GroupCheckOutRow $row, ?Folio $target, string $missingTarget): array
    {
        $none = BigDecimal::zero();
        $folios = $this->checkOutSettlement->folios($unit);
        $lines = $this->checkOutSettlement->movableLines($folios);

        if ($lines->isEmpty()) {
            $balanced = ! $folios->contains(fn (Folio $folio): bool => ! BigDecimal::of($this->folioBalance->forFolio($folio))->isZero());

            return ['refusal' => $balanced ? null : CheckOutGroupResult::REFUSAL_NOTHING_TO_MOVE, 'moved' => $none, 'refunded' => $none];
        }

        if ($target === null) {
            return ['refusal' => $missingTarget, 'moved' => $none, 'refunded' => $none];
        }

        if ($this->checkOutSettlement->holdsUnrefundable($folios)) {
            return ['refusal' => CheckOutGroupResult::REFUSAL_PAYMENT_NOT_REFUNDABLE, 'moved' => $none, 'refunded' => $none];
        }

        $posted = ($this->transferLines)($actor, array_values($lines->all()), $target);

        if ($posted->isRefused()) {
            return ['refusal' => (string) $posted->refusal, 'moved' => $none, 'refunded' => $none];
        }

        $moved = array_reduce($posted->lines, fn (BigDecimal $sum, FolioLine $copy): BigDecimal => $sum->plus($copy->amount), BigDecimal::zero());
        $refunded = BigDecimal::zero();

        foreach ($this->checkOutSettlement->heldPayments($this->checkOutSettlement->folios($unit)) as $payment) {
            $part = $this->checkOutSettlement->refundable($payment);
            $result = ($this->refundPayment)($actor, $payment, (string) $part->toScale(2), null, $row->idempotencyKey.':'.$payment->id);

            if ($result->isRefused()) {
                return ['refusal' => (string) $result->refusal, 'moved' => $none, 'refunded' => $none];
            }

            $refunded = $refunded->plus($part);
        }

        return ['refusal' => null, 'moved' => $moved, 'refunded' => $refunded];
    }

    /**
     * Record One payment for all on the paying room's folio for what was moved there (plus the payer's own balance when
     * its row is Collect). The server's total must equal the amount the page showed. A repeat of the save finds its
     * payment and does nothing.
     *
     * @param  array{collected: BigDecimal, refunded: BigDecimal, moved: BigDecimal}  $totals
     */
    private function takeOnePayment(User $actor, Reservation $reservation, OnePayment $onePayment, ?Folio $payerFolio, BigDecimal $pool, array &$totals): ?string
    {
        $key = $onePayment->idempotencyKey.':all';

        if (FolioLine::query()->where('idempotency_key', 'payment:'.$key)->exists()) {
            return null;
        }

        if (! $pool->toScale(2)->isEqualTo(BigDecimal::of($onePayment->amount)->toScale(2))) {
            return CheckOutGroupResult::REFUSAL_AMOUNT_CHANGED;
        }

        if (! $pool->isPositive()) {
            return CheckOutGroupResult::REFUSAL_NOTHING_TO_COLLECT;
        }

        if ($payerFolio === null) {
            return CheckOutGroupResult::REFUSAL_INVALID_PAYER;
        }

        $payment = ($this->recordPayment)($actor, $reservation, $payerFolio, null, new NewPaymentData(
            $onePayment->paymentMethodId,
            PaymentPurpose::Settlement,
            (string) $pool->toScale(2),
            $onePayment->reference,
            $key,
        ));

        if ($payment->isRefused()) {
            return (string) $payment->refusal;
        }

        $totals['collected'] = $totals['collected']->plus($pool);

        return null;
    }

    /**
     * The money of a room that is already out, cancelled or no-show: its payment, its refund through the held
     * payments, or what its move left. No check-out runs. When something was done the room's folios must end at 0.
     *
     * @param  array{collected: BigDecimal, refunded: BigDecimal, moved: BigDecimal}  $totals
     */
    private function settleMoney(User $actor, Reservation $reservation, ReservationUnit $unit, GroupCheckOutRow $row, ?CheckOutCollectData $collect, ?string $refundAmount, bool $touched, array &$totals): ?string
    {
        if ($collect !== null) {
            $payment = ($this->recordPayment)($actor, $reservation, null, $unit, new NewPaymentData(
                $collect->paymentMethodId,
                PaymentPurpose::Settlement,
                $collect->amount,
                $collect->reference,
                $collect->idempotencyKey.':collect',
            ));

            if ($payment->isRefused()) {
                return (string) $payment->refusal;
            }

            $touched = true;

            if (! $payment->replayed) {
                $totals['collected'] = $totals['collected']->plus($collect->amount);
            }
        }

        if ($refundAmount !== null && BigDecimal::of($refundAmount)->isPositive()) {
            $refusal = $this->refundHeld($actor, $unit, $refundAmount, $row->idempotencyKey, $totals);

            if ($refusal !== null) {
                return $refusal;
            }

            $touched = true;
        }

        if ($touched && $this->checkOutSettlement->folios($unit)->contains(fn (Folio $folio): bool => ! BigDecimal::of($this->folioBalance->forFolio($folio))->isZero())) {
            return UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED;
        }

        return null;
    }

    /**
     * Give back an amount of the credit from the payments the room's Open folios hold, oldest first. Refunds of this
     * save that already exist (a repeat) count as given, so nothing is refunded twice.
     *
     * @param  array{collected: BigDecimal, refunded: BigDecimal, moved: BigDecimal}  $totals
     */
    private function refundHeld(User $actor, ReservationUnit $unit, string $amount, string $uuid, array &$totals): ?string
    {
        $folios = $this->checkOutSettlement->folios($unit);
        $done = BigDecimal::of((string) (Payment::query()
            ->whereIn('folio_id', $folios->modelKeys())
            ->whereNotNull('refund_of_id')
            ->whereIn('folio_line_id', FolioLine::query()->where('idempotency_key', 'like', 'refund:'.$uuid.':%')->select('id'))
            ->sum('amount') ?: '0'));
        $left = BigDecimal::of($amount)->minus($done);

        if (! $left->isPositive()) {
            return null;
        }

        $payments = $this->checkOutSettlement->heldPayments($folios);
        $held = $payments->reduce(fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($this->checkOutSettlement->refundable($payment)), BigDecimal::zero());

        if ($left->isGreaterThan($held)) {
            return 'refund_exceeds_payment';
        }

        foreach ($payments as $payment) {
            if ($left->isZero()) {
                break;
            }

            $refundable = $this->checkOutSettlement->refundable($payment);
            $part = $left->isLessThan($refundable) ? $left : $refundable;
            $result = ($this->refundPayment)($actor, $payment, (string) $part->toScale(2), null, $uuid.':'.$payment->id);

            if ($result->isRefused()) {
                return (string) $result->refusal;
            }

            $totals['refunded'] = $totals['refunded']->plus($part);
            $left = $left->minus($part);
        }

        return null;
    }

    private function masterFolio(Reservation $reservation): ?Folio
    {
        return Folio::query()
            ->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->where('type', FolioType::Master)
            ->where('status', FolioStatus::Open)
            ->oldest('id')
            ->first();
    }

    private function openUnitFolio(ReservationUnit $unit): ?Folio
    {
        return $this->checkOutSettlement->folios($unit)->first(fn (Folio $folio): bool => $folio->status === FolioStatus::Open);
    }

    private function ownBalance(ReservationUnit $unit): BigDecimal
    {
        return $this->checkOutSettlement->folios($unit)->reduce(
            fn (BigDecimal $sum, Folio $folio): BigDecimal => $sum->plus($this->folioBalance->forFolio($folio)),
            BigDecimal::zero(),
        );
    }

    /**
     * The payments this save recorded: the Collect payments and One payment for all, found by their key prefix.
     *
     * @param  list<GroupCheckOutRow>  $rows
     * @return list<int>
     */
    private function paymentIds(array $rows, ?OnePayment $onePayment): array
    {
        $uuid = $rows[0]->idempotencyKey ?? $onePayment?->idempotencyKey;

        if ($uuid === null) {
            return [];
        }

        $lines = FolioLine::query()->where('idempotency_key', 'like', 'payment:'.$uuid.':%')->select('id');

        return array_values(Payment::query()->whereIn('folio_line_id', $lines)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    private function log(User $actor, Reservation $reservation, CheckOutGroupResult $result, bool $onePayment, string $businessDate): void
    {
        $changed = $result->checkedOutUnitIds !== [] || $result->invoice !== null
            || BigDecimal::of($result->collected)->isPositive() || BigDecimal::of($result->refunded)->isPositive() || BigDecimal::of($result->movedToMaster)->isPositive();

        if (! $changed) {
            return;
        }

        activity()
            ->performedOn($reservation)
            ->causedBy($actor)
            ->event('reservation.group_checked_out')
            ->withProperties([
                'property_id' => $reservation->property_id,
                'checked_out_unit_ids' => $result->checkedOutUnitIds,
                'settled_unit_ids' => $result->settledUnitIds,
                'collected' => $result->collected,
                'refunded' => $result->refunded,
                'moved_to_master' => $result->movedToMaster,
                'one_payment' => $onePayment,
                'payment_ids' => $result->paymentIds,
                'ar_invoice_id' => $result->invoice?->id,
                'business_date' => $businessDate,
            ])
            ->log('Group checked out');
    }
}

<?php

namespace App\Actions\FrontDesk;

use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\CheckOutCollectData;
use App\Data\Reservations\CheckOutDamageData;
use App\Enums\PaymentPurpose;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\CheckOutSettlement;
use App\Services\FrontDesk\DamageChargeItem;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CheckOutAndRefund
{
    public function __construct(
        private CheckOutUnit $checkOutUnit,
        private RecordPayment $recordPayment,
        private RefundPayment $refundPayment,
        private CheckOutSettlement $checkOutSettlement,
        private PostPendingNight $postPendingNight,
        private PostCharge $postCharge,
        private DamageChargeItem $damageChargeItem,
    ) {}

    /**
     * The check-out form: collect the balance, refund the credit, then check the unit out with `CheckOutUnit`, in one
     * transaction. `$collect` records a Settlement payment on the unit folio with `RecordPayment`, for the amount the
     * form showed. `$refundAmount` is taken from the refund pool in order (the security deposits oldest first, then the
     * other payments oldest first, see `CheckOutSettlement::refundPool()`), each part through `RefundPayment`. An
     * amount above the larger of the deposit held and the credit, or above what the pool holds, is refused with
     * `refund_exceeds_payment` before anything is refunded.
     * A refusal of the payment, the refund or the check-out (for example `balance_not_settled` when the amount no
     * longer settles the folio) comes back as the refusal code and rolls every step back. `$idempotencyKey` is a UUID
     * from the form: the payment uses `{uuid}:collect` and each refund `{uuid}:{payment id}`. Permissions are checked
     * by the route and the Form Request. While the night audit is pending, `PostPendingNight` runs first, so the balance
     * the payment and the refund settle includes the night of the business date. See docs/18-front-desk/01-check-in-and-check-out.md#check-out.
     *
     * `$damage` posts a damage charge on the property's damage charge item (`DamageChargeItem`), on the unit's own folio
     * with the key `{uuid}:damage`, before the payment and the refund, so the deposit offsets it. With no damage charge
     * item it refuses `damage_not_allowed`, and an amount the item's fixed price does not allow `damage_price_fixed`. Other
     * `PostCharge` refusals come back as they are.
     */
    public function __invoke(User $actor, ReservationUnit $unit, ?string $refundAmount = null, ?CheckOutCollectData $collect = null, ?string $idempotencyKey = null, ?CheckOutDamageData $damage = null): UnitStayResult
    {
        DB::beginTransaction();

        try {
            $result = $this->run($actor, $unit, $refundAmount, $collect, $idempotencyKey, $damage);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }

    private function run(User $actor, ReservationUnit $unit, ?string $refundAmount, ?CheckOutCollectData $collect, ?string $idempotencyKey, ?CheckOutDamageData $damage): UnitStayResult
    {
        try {
            ($this->postPendingNight)($actor, $unit->refresh());
        } catch (UnitStayRefused $refused) {
            return $refused->result;
        }

        if ($damage !== null) {
            $refusal = $this->postDamage($actor, $unit, $damage, $idempotencyKey ?? (string) Str::uuid());

            if ($refusal !== null) {
                return UnitStayResult::refused($refusal);
            }
        }

        if ($collect !== null) {
            $payment = ($this->recordPayment)($actor, $unit->reservation, null, $unit, new NewPaymentData(
                $collect->paymentMethodId,
                PaymentPurpose::Settlement,
                $collect->amount,
                $collect->reference,
                $collect->idempotencyKey.':collect',
            ));

            if ($payment->isRefused()) {
                return UnitStayResult::refused((string) $payment->refusal);
            }
        }

        if ($refundAmount !== null && BigDecimal::of($refundAmount)->isPositive()) {
            $folios = $this->checkOutSettlement->folios($unit);
            $left = BigDecimal::of($refundAmount);
            $pool = $this->checkOutSettlement->refundPool($folios);
            $refundable = $pool->reduce(fn (BigDecimal $sum, $payment): BigDecimal => $sum->plus($this->checkOutSettlement->refundable($payment)), BigDecimal::zero());
            $deposit = $pool->filter(fn ($payment): bool => $payment->purpose === PaymentPurpose::SecurityDeposit)
                ->reduce(fn (BigDecimal $sum, $payment): BigDecimal => $sum->plus($this->checkOutSettlement->refundable($payment)), BigDecimal::zero());
            $credit = $this->checkOutSettlement->balance($folios);
            $credit = $credit->isNegative() ? $credit->negated() : BigDecimal::zero();
            $limit = $credit->isGreaterThan($deposit) ? $credit : $deposit;

            if ($left->isGreaterThan($limit) || $left->isGreaterThan($refundable)) {
                return UnitStayResult::refused('refund_exceeds_payment');
            }

            foreach ($pool as $payment) {
                if ($left->isZero()) {
                    break;
                }

                $part = $left->isLessThan($this->checkOutSettlement->refundable($payment)) ? $left : $this->checkOutSettlement->refundable($payment);
                $result = ($this->refundPayment)($actor, $payment, (string) $part->toScale(2), null, ($idempotencyKey ?? '').':'.$payment->id);

                if ($result->isRefused()) {
                    return UnitStayResult::refused((string) $result->refusal);
                }

                $left = $left->minus($part);
            }
        }

        return ($this->checkOutUnit)($actor, $unit);
    }

    /**
     * Post the damage charge on the unit's own folio. Returns the refusal code, or null when it posted.
     */
    private function postDamage(User $actor, ReservationUnit $unit, CheckOutDamageData $damage, string $idempotencyKey): ?string
    {
        $item = $this->damageChargeItem->for($unit->property);

        if ($item === null) {
            return UnitStayResult::REFUSAL_DAMAGE_NOT_ALLOWED;
        }

        $result = ($this->postCharge)($actor, $unit, new NewChargeData(
            chargeItemId: $item->id,
            transactionCodeId: null,
            description: $damage->note === null ? null : $item->name.' · '.$damage->note,
            quantity: 1,
            unitAmount: $damage->amount,
            idempotencyKey: $idempotencyKey.':damage',
            ownFolio: true,
        ));

        return $result->refusal === PostingResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED ? UnitStayResult::REFUSAL_DAMAGE_PRICE_FIXED : $result->refusal;
    }
}

<?php

namespace App\Actions\Payments;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\FrontDesk\CheckOutSettlement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Throwable;

class RefundFolioCredit
{
    public function __construct(
        private RefundPayment $refundPayment,
        private CheckOutSettlement $settlement,
        private FolioBalance $folioBalance,
    ) {}

    /**
     * Give back the credit of an Open folio without a check-out. The parts come from `CheckOutSettlement::refundPool()`
     * (security deposits first, then the other payments, oldest first) and each goes through `RefundPayment` with the
     * key `{key}:{payment id}`, so a repeat returns the first refund and writes nothing. The amount is at most the credit and at most what
     * the payments hold. A folio that holds money no payment can give back offers no refund. Any refusal rolls back
     * every part. See docs/14-payments/01-payments-and-refunds.md.
     */
    public function __invoke(User $actor, Folio $folio, string $amount, ?int $methodId, ?string $reason, string $idempotencyKey): PaymentResult
    {
        DB::beginTransaction();

        try {
            $result = $this->refundParts($actor, $folio, $amount, $methodId, $reason, $idempotencyKey);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }

    private function refundParts(User $actor, Folio $folio, string $amount, ?int $methodId, ?string $reason, string $idempotencyKey): PaymentResult
    {

        $locked = Folio::query()->lockForUpdate()->findOrFail($folio->id);

        $existing = FolioLine::query()->where('folio_id', $locked->id)->where('idempotency_key', 'like', 'refund:'.$idempotencyKey.':%')->orderBy('id')->first();
        $replayed = $existing === null ? null : Payment::query()->where('folio_line_id', $existing->id)->first();

        if ($replayed !== null) {
            return PaymentResult::replayed($replayed);
        }

        if ($locked->status !== FolioStatus::Open) {
            return PaymentResult::refused(PaymentResult::REFUSAL_FOLIO_NOT_OPEN);
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1 || (float) $amount <= 0) {
            return PaymentResult::refused(PaymentResult::REFUSAL_AMOUNT_INVALID);
        }

        $folios = $locked->newCollection([$locked]);
        $balance = BigDecimal::of($this->folioBalance->forFolio($locked));
        $pool = $this->settlement->refundPool($folios);
        $held = $pool->reduce(fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($this->settlement->refundable($payment)), BigDecimal::zero());
        $credit = $balance->isNegative() ? $balance->negated() : BigDecimal::zero();
        $wanted = BigDecimal::of($amount);

        if ($this->settlement->holdsUnrefundable($folios) || ! $credit->isPositive() || $wanted->isGreaterThan($credit) || $wanted->isGreaterThan($held)) {
            return PaymentResult::refused(PaymentResult::REFUSAL_CREDIT_NOT_REFUNDABLE);
        }

        $left = $wanted;
        $first = null;

        foreach ($pool as $payment) {
            if ($left->isZero()) {
                break;
            }

            $available = $this->settlement->refundable($payment);
            $part = $left->isLessThan($available) ? $left : $available;
            $result = ($this->refundPayment)($actor, $payment, (string) $part->toScale(2), null, $idempotencyKey.':'.$payment->id, methodId: $methodId, reason: $reason);

            if ($result->isRefused()) {
                return $result;
            }

            $first ??= $result->payment;
            $left = $left->minus($part);
        }

        return PaymentResult::recorded($first, []);

    }
}

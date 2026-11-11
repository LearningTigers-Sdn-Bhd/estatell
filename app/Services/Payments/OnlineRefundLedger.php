<?php

namespace App\Services\Payments;

use App\Enums\GatewayRefundStatus;
use App\Enums\PaymentStatus;
use App\Models\Folio;
use App\Models\GatewayRefund;
use App\Models\GatewayRefundPart;
use App\Models\Payment;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

/**
 * What online refunds waiting on Curlec hold back. The folio ledger changes only when Curlec confirms, so a Pending refund
 * counts against the amount left to refund and the credit, and a Failed one gives its amount back.
 * See docs/29-booking-engine/05-cancel-and-refund.md#refund-status.
 */
class OnlineRefundLedger
{
    /** Eager-load the Pending sum as `pending_refunds_sum_amount`: `withSum([PENDING_SUM_RELATION => pendingParts(...)], 'amount')`. */
    public const string PENDING_SUM_RELATION = 'gatewayRefundParts as pending_refunds_sum_amount';

    /**
     * @param  Builder<GatewayRefundPart>  $query
     */
    public static function pendingParts(Builder $query): void
    {
        $query->whereHas('refund', fn (Builder $refund) => $refund->where('status', GatewayRefundStatus::Pending));
    }

    public function pendingOnPayment(Payment $payment): BigDecimal
    {
        $query = GatewayRefundPart::query()->where('payment_id', $payment->id);
        self::pendingParts($query);

        return BigDecimal::of((string) ($query->sum('amount') ?: '0'));
    }

    public function pendingOnFolio(Folio $folio): BigDecimal
    {
        $query = GatewayRefundPart::query()->whereHas('payment', fn (Builder $payment) => $payment->where('folio_id', $folio->id));
        self::pendingParts($query);

        return BigDecimal::of((string) ($query->sum('amount') ?: '0'));
    }

    /**
     * What is left of a payment after its refunds and its Pending online refunds.
     */
    public function leftOnPayment(Payment $payment): BigDecimal
    {
        return BigDecimal::of($payment->amount)
            ->minus((string) ($payment->refunds()->sum('amount') ?: '0'))
            ->minus($this->pendingOnPayment($payment));
    }

    /**
     * Whether every part still has its money: staff may have given it back another way since this refund failed.
     */
    public function canStillRun(GatewayRefund $refund): bool
    {
        foreach ($refund->parts()->with('payment')->get() as $part) {
            if ($part->payment->status !== PaymentStatus::Completed || $this->leftOnPayment($part->payment)->isLessThan($part->amount)) {
                return false;
            }
        }

        return true;
    }
}

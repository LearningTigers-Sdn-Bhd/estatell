<?php

namespace App\Actions\Payments;

use App\Enums\FolioStatus;
use App\Enums\GatewayPaymentStatus;
use App\Enums\GatewayRefundStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Jobs\SendOnlineRefundJob;
use App\Models\Folio;
use App\Models\GatewayPayment;
use App\Models\GatewayRefund;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\OnlineRefundLedger;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RequestOnlineRefund
{
    public function __construct(private OnlineRefundLedger $ledger) {}

    /**
     * Save a Pending refund of one Curlec payment and send it to Curlec after commit. Nothing posts on the folio until the
     * webhook confirms (`CompleteOnlineRefund`). `$parts` names each `payments` row and its share, and one refund for a
     * group costs one Curlec fee. A late payment whose rooms are taken has no parts. The Curlec payment and the folios are
     * locked first, so two requests never exceed a payment. A null actor is an automatic refund. A repeat of `$idempotencyKey`
     * returns the first refund. See docs/29-booking-engine/05-cancel-and-refund.md#refund-status.
     *
     * @param  list<array{payment: Payment, amount: string}>  $parts
     */
    public function __invoke(?User $actor, GatewayPayment $gatewayPayment, array $parts, string $amount, string $idempotencyKey, ?string $reason = null): OnlineRefundResult
    {
        return DB::transaction(function () use ($actor, $gatewayPayment, $parts, $amount, $idempotencyKey, $reason): OnlineRefundResult {
            $locked = GatewayPayment::query()->lockForUpdate()->findOrFail($gatewayPayment->id);
            $existing = GatewayRefund::query()->where('property_id', $locked->property_id)->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return OnlineRefundResult::replayed($existing);
            }

            if ($locked->status !== GatewayPaymentStatus::Paid || $locked->gateway_payment_id === null) {
                return OnlineRefundResult::refused(PaymentResult::REFUSAL_NOT_REFUNDABLE);
            }

            if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1 || BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
                return OnlineRefundResult::refused(PaymentResult::REFUSAL_AMOUNT_INVALID);
            }

            $sum = array_reduce($parts, fn (BigDecimal $total, array $part): BigDecimal => $total->plus($part['amount']), BigDecimal::zero());

            if ($parts !== [] && ! $sum->isEqualTo($amount)) {
                throw new InvalidArgumentException('The parts of an online refund must add up to its amount.');
            }

            $taken = GatewayRefund::query()->where('gateway_payment_id', $locked->id)->whereIn('status', [GatewayRefundStatus::Pending, GatewayRefundStatus::Processed])->sum('amount');

            if (BigDecimal::of($amount)->isGreaterThan(BigDecimal::of($locked->amount)->minus((string) ($taken ?: '0')))) {
                return OnlineRefundResult::refused(PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT);
            }

            $refusal = $this->refusalForParts($parts);

            if ($refusal !== null) {
                return OnlineRefundResult::refused($refusal);
            }

            $refund = GatewayRefund::query()->create([
                'property_id' => $locked->property_id,
                'gateway_payment_id' => $locked->id,
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'status' => GatewayRefundStatus::Pending,
                'reason' => $reason,
                'requested_by' => $actor?->id,
            ]);

            foreach ($parts as $part) {
                $refund->parts()->create(['payment_id' => $part['payment']->id, 'amount' => $part['amount']]);
            }

            $first = $parts[0]['payment'] ?? null;

            activity()
                ->performedOn($first === null ? $refund : $first->folio->reservation)
                ->causedBy($actor)
                ->event('payment.online_refund_requested')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'gateway_refund_id' => $refund->id,
                    'amount' => $refund->amount,
                    'reason' => $reason,
                ])
                ->log('Online refund requested');

            SendOnlineRefundJob::dispatch($refund->id)->afterCommit();

            return OnlineRefundResult::requested($refund);
        });
    }

    /**
     * @param  list<array{payment: Payment, amount: string}>  $parts
     */
    private function refusalForParts(array $parts): ?string
    {
        $folioIds = array_map(fn (array $part): int => $part['payment']->folio_id, $parts);
        /** @var Collection<int, Folio> $folios */
        $folios = Folio::query()->whereIn('id', $folioIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($parts as $part) {
            $payment = $part['payment'];

            if ($payment->refund_of_id !== null || $payment->status !== PaymentStatus::Completed || $payment->paymentMethod->type !== PaymentMethodType::Online) {
                return PaymentResult::REFUSAL_NOT_REFUNDABLE;
            }

            if ($folios->get($payment->folio_id)?->status !== FolioStatus::Open) {
                return PaymentResult::REFUSAL_FOLIO_NOT_OPEN;
            }

            if ($this->ledger->leftOnPayment($payment)->isLessThan($part['amount'])) {
                return PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT;
            }
        }

        return null;
    }
}

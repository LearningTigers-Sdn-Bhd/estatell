<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosChargeData;
use App\Data\Pos\PosVoidData;
use App\Models\PosConnection;
use App\Models\PosGuestLink;
use App\Models\PosOutletMapping;
use App\Models\PosPosting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Turn one Loyverse receipt from a `receipts.update` webhook into a POS posting. Receipts of a store with no active mapping
 * are ignored, and so is a receipt with no "Room Charge" tender: only the Room Charge part of a split payment is posted,
 * never the cash or card part. A sale posts to the room of its linked customer (no linked customer waits for review with
 * a hint); a refund voids `refund_for`; a cancelled sale voids itself. Loyverse sends the same receipt again on
 * every update, so the posting Actions' idempotency absorbs repeats. A receipt's id is its `receipt_number`.
 * See docs/33-pos/04-loyverse-integration.md#webhook-ingestion-loyverse--estatell.
 */
class ProcessLoyverseReceipt
{
    public function __construct(private PostPosCharge $postPosCharge, private VoidPosCharge $voidPosCharge) {}

    /**
     * @param  array<string, mixed>  $receipt
     */
    public function __invoke(PosConnection $connection, array $receipt): ?PosPosting
    {
        $number = (string) ($receipt['receipt_number'] ?? '');
        $mapping = PosOutletMapping::query()
            ->where('pos_connection_id', $connection->id)
            ->where('external_store_id', (string) ($receipt['store_id'] ?? ''))
            ->active()
            ->with('outlet')
            ->first();

        if ($number === '' || $mapping === null) {
            return null;
        }

        $amount = $this->roomChargeAmount($receipt, $mapping);

        if ($amount->isLessThanOrEqualTo(0)) {
            return null;
        }

        $outlet = $mapping->outlet;
        $isRefund = ($receipt['receipt_type'] ?? 'SALE') === 'REFUND';

        if ($isRefund && filled($receipt['refund_for'] ?? null)) {
            return ($this->voidPosCharge)($outlet, new PosVoidData('refund:'.$number, (string) $receipt['refund_for'], (string) $amount, 'Refunded in Loyverse', $receipt, $connection->id))->posting;
        }

        if ($isRefund) {
            return null;
        }

        if (($receipt['cancelled_at'] ?? null) !== null) {
            return ($this->voidPosCharge)($outlet, new PosVoidData('cancel:'.$number, $number, null, 'Cancelled in Loyverse', $receipt, $connection->id))->posting;
        }

        $unitId = filled($receipt['customer_id'] ?? null)
            ? PosGuestLink::query()->where('pos_connection_id', $connection->id)->where('external_customer_id', (string) $receipt['customer_id'])->value('reservation_unit_id')
            : null;

        return ($this->postPosCharge)($outlet, new PosChargeData(
            $number,
            $number,
            (string) $amount,
            $unitId === null ? null : (int) $unitId,
            $receipt,
            $connection->id,
            failureHint: $unitId === null ? 'No linked in-house guest on receipt' : null,
        ))->posting;
    }

    /**
     * The sum of the payments made with the mapped Room Charge payment type, with two decimals.
     *
     * @param  array<string, mixed>  $receipt
     */
    private function roomChargeAmount(array $receipt, PosOutletMapping $mapping): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ((array) ($receipt['payments'] ?? []) as $payment) {
            if (($payment['payment_type_id'] ?? null) === $mapping->external_payment_type_id) {
                $sum = $sum->plus(BigDecimal::of((string) ($payment['money_amount'] ?? 0)));
            }
        }

        return $sum->toScale(2, RoundingMode::HalfUp);
    }
}

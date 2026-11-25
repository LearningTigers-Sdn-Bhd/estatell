<?php

namespace App\Actions\Pos;

use App\Enums\PosOrderStatus;
use App\Features\Pos;
use App\Models\PosOrder;
use App\Services\EInvoice\PosOrderReportLines;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * The first step of a guest claiming an e-invoice for their own walk-in receipt: holding the slip is the proof, and the proof
 * is the exact total and the date on it. Every try is counted on the order and a token is closed after 10 tries
 * (`receipt_attempts_exceeded`), whether or not they were right, so a slip cannot be guessed at. A token that does not exist
 * or is not a settled sale looks the same as one that never was (`receipt_not_found`). A sale already on an e-invoice, or past
 * the end of its month, or with nothing left to report (all on a room), is closed (`receipt_closed`). A wrong total or date
 * is `receipt_mismatch`. Nothing about the buyer is read or written here.
 */
class ConfirmPosReceiptClaim
{
    public const int MAX_ATTEMPTS = 10;

    public function __construct(private PosOrderReportLines $posOrderReportLines) {}

    /**
     * @return array{order: PosOrder|null, refusal: string|null}
     */
    public function __invoke(string $token, string $total, string $date): array
    {
        return DB::transaction(function () use ($token, $total, $date): array {
            $order = strlen($token) === 32 ? PosOrder::query()->where('receipt_claim_token', $token)->lockForUpdate()->first() : null;

            if ($order === null || $order->status !== PosOrderStatus::Settled || $order->order_type->value !== 'sale' || ! Feature::for($order->property)->active(Pos::class)) {
                return ['order' => null, 'refusal' => 'receipt_not_found'];
            }

            if ($order->receipt_claim_attempts >= self::MAX_ATTEMPTS) {
                return ['order' => null, 'refusal' => 'receipt_attempts_exceeded'];
            }

            $order->forceFill(['receipt_claim_attempts' => $order->receipt_claim_attempts + 1])->save();

            if ($this->closed($order)) {
                return ['order' => null, 'refusal' => 'receipt_closed'];
            }

            return $this->matches($order, $total, $date)
                ? ['order' => $order, 'refusal' => null]
                : ['order' => null, 'refusal' => 'receipt_mismatch'];
        });
    }

    /**
     * Whether a settled sale can no longer be claimed: already on an e-invoice, past the end of its month, or nothing of it is
     * left to report.
     */
    public function closed(PosOrder $order): bool
    {
        return $order->e_invoice_submission_id !== null
            || $order->business_date === null
            || now()->greaterThan($order->business_date->endOfMonth())
            || ! $this->posOrderReportLines->reportable($order);
    }

    private function matches(PosOrder $order, string $total, string $date): bool
    {
        try {
            $amount = BigDecimal::of(str_replace(',', '', trim($total)));
        } catch (MathException) {
            return false;
        }

        return $amount->isEqualTo(BigDecimal::of($order->grand_total))
            && in_array(trim($date), [$order->business_date?->toDateString(), $order->settled_at?->toDateString()], true);
    }
}

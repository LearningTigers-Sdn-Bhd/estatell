<?php

namespace App\Actions\Payments;

use App\Enums\GatewayRefundStatus;
use App\Models\GatewayRefund;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteOnlineRefund
{
    public function __construct(private RefundPayment $refundPayment) {}

    /**
     * Curlec gave the money back: mark the refund Processed and post one refund line for each part, under the person who
     * asked (null for an automatic refund). The status changes first, so the last line can close a settled folio. The keys
     * make a repeat post nothing. Returns the refusal code when a line could not post: everything rolls back and the refund
     * stays Pending for the queue to try again. See docs/29-booking-engine/05-cancel-and-refund.md#refund-status.
     *
     * @param  array<string, mixed>|null  $entity  Curlec's refund entity.
     */
    public function __invoke(GatewayRefund $refund, string $curlecRefundId, ?array $entity): ?string
    {
        DB::beginTransaction();

        try {
            $refusal = $this->complete($refund, $curlecRefundId, $entity);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $refusal === null ? DB::commit() : DB::rollBack();

        return $refusal;
    }

    /**
     * @param  array<string, mixed>|null  $entity
     */
    private function complete(GatewayRefund $refund, string $curlecRefundId, ?array $entity): ?string
    {
        $locked = GatewayRefund::query()->lockForUpdate()->findOrFail($refund->id);

        if (! $locked->status->canTransitionTo(GatewayRefundStatus::Processed)) {
            return null;
        }

        $locked->update([
            'status' => GatewayRefundStatus::Processed,
            'gateway_refund_id' => $curlecRefundId,
            'failure_reason' => null,
            'gateway_payload' => $entity,
        ]);

        $actor = $locked->requester;

        foreach ($locked->parts()->with('payment')->orderBy('id')->get() as $part) {
            $result = ($this->refundPayment)($actor, $part->payment, $part->amount, $curlecRefundId, "online:{$locked->id}:{$part->id}", confirmedByCurlec: true);

            if ($result->isRefused()) {
                return (string) $result->refusal;
            }
        }

        return null;
    }
}

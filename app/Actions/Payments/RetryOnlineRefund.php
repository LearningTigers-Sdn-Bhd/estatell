<?php

namespace App\Actions\Payments;

use App\Enums\FolioStatus;
use App\Enums\GatewayRefundStatus;
use App\Jobs\SendOnlineRefundJob;
use App\Models\GatewayRefund;
use App\Models\GatewayRefundPart;
use App\Models\User;
use App\Services\Payments\OnlineRefundLedger;
use Illuminate\Support\Facades\DB;

class RetryOnlineRefund
{
    public function __construct(private OnlineRefundLedger $ledger) {}

    /**
     * Ask Curlec again for a Failed refund. It is Pending again with a new attempt, so Curlec sees a new receipt. Refused
     * when the money was given back another way since it failed, or the folio is no longer Open.
     * See docs/29-booking-engine/05-cancel-and-refund.md#when-a-refund-cannot-run.
     */
    public function __invoke(User $actor, GatewayRefund $refund): OnlineRefundResult
    {
        return DB::transaction(function () use ($actor, $refund): OnlineRefundResult {
            $locked = GatewayRefund::query()->lockForUpdate()->findOrFail($refund->id);

            if (! $locked->status->canTransitionTo(GatewayRefundStatus::Pending)) {
                return OnlineRefundResult::refused(PaymentResult::REFUSAL_NOT_REFUNDABLE);
            }

            $parts = $locked->parts()->with('payment.folio.reservation')->get();

            if ($parts->contains(fn (GatewayRefundPart $part): bool => $part->payment->folio->status !== FolioStatus::Open)) {
                return OnlineRefundResult::refused(PaymentResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            if (! $this->ledger->canStillRun($locked)) {
                return OnlineRefundResult::refused(PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT);
            }

            $locked->update([
                'status' => GatewayRefundStatus::Pending,
                'attempts' => $locked->attempts + 1,
                'gateway_refund_id' => null,
                'failure_reason' => null,
                'gateway_payload' => null,
                'requested_by' => $actor->id,
            ]);

            $first = $parts->first();

            activity()
                ->performedOn($first === null ? $locked : $first->payment->folio->reservation)
                ->causedBy($actor)
                ->event('payment.online_refund_retried')
                ->withProperties(['property_id' => $locked->property_id, 'gateway_refund_id' => $locked->id, 'amount' => $locked->amount])
                ->log('Online refund retried');

            SendOnlineRefundJob::dispatch($locked->id)->afterCommit();

            return OnlineRefundResult::requested($locked);
        });
    }
}

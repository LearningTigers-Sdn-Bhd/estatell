<?php

namespace App\Actions\Payments;

use App\Enums\GatewayRefundStatus;
use App\Models\GatewayRefund;
use Illuminate\Support\Facades\DB;

class FailOnlineRefund
{
    public function __construct(private AlertOnlineRefundProblem $alert) {}

    /**
     * Curlec could not give the money back. The refund becomes Failed, its credit stays on the folio for staff to retry, and
     * staff and admins are alerted once. See docs/29-booking-engine/05-cancel-and-refund.md#refund-status.
     *
     * @param  array<string, mixed>|null  $entity  Curlec's refund entity, when a webhook reported it.
     */
    public function __invoke(GatewayRefund $refund, string $reason, ?array $entity = null): void
    {
        $failed = DB::transaction(function () use ($refund, $reason, $entity): ?GatewayRefund {
            $locked = GatewayRefund::query()->lockForUpdate()->findOrFail($refund->id);

            if (! $locked->status->canTransitionTo(GatewayRefundStatus::Failed)) {
                return null;
            }

            $locked->update([
                'status' => GatewayRefundStatus::Failed,
                'failure_reason' => mb_substr($reason, 0, 255),
                'gateway_payload' => $entity ?? $locked->gateway_payload,
            ]);

            return $locked;
        });

        if ($failed !== null) {
            ($this->alert)($failed, __('A refund of :amount failed at Curlec (:problem). The credit stays on the folio. Retry it from the folio.', ['amount' => $failed->amount, 'problem' => $failed->failure_reason]));
        }
    }
}

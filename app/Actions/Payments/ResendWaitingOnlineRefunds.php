<?php

namespace App\Actions\Payments;

use App\Enums\GatewayRefundStatus;
use App\Jobs\SendOnlineRefundJob;
use App\Models\GatewayRefund;

class ResendWaitingOnlineRefunds
{
    private const int WAIT_MINUTES = 15;

    /**
     * Queue again every Pending refund that has no Curlec ID after the wait, such as one that met a low Curlec balance.
     * Returns how many were queued. See docs/29-booking-engine/05-cancel-and-refund.md#when-a-refund-cannot-run.
     */
    public function __invoke(): int
    {
        $count = 0;

        GatewayRefund::query()
            ->where('status', GatewayRefundStatus::Pending)
            ->whereNull('gateway_refund_id')
            ->where('updated_at', '<=', now()->subMinutes(self::WAIT_MINUTES))
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$count): void {
                SendOnlineRefundJob::dispatch($id);
                $count++;
            });

        return $count;
    }
}

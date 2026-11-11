<?php

namespace App\Jobs;

use App\Actions\Payments\SendOnlineRefund;
use App\Models\GatewayRefund;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one Pending refund to Curlec, outside the request that asked for it. A try that fails stays Pending, and the
 * scheduled `ResendWaitingOnlineRefunds` queues it again, so this job does not retry by itself.
 */
class SendOnlineRefundJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $gatewayRefundId) {}

    public function handle(SendOnlineRefund $send): void
    {
        $refund = GatewayRefund::query()->find($this->gatewayRefundId);

        if ($refund !== null) {
            $send($refund);
        }
    }
}

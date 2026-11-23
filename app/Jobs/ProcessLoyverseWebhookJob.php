<?php

namespace App\Jobs;

use App\Actions\Pos\ProcessLoyverseReceipt;
use App\Enums\PosConnectionStatus;
use App\Enums\PosProvider;
use App\Features\PosIntegration;
use App\Models\PosConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Pennant\Feature;

/**
 * Process one `receipts.update` notification: find the connection by merchant id (ignored when it is disconnected or the
 * property's POS integration is off), then post each receipt. Posting is idempotent per receipt, so a retry of the whole
 * job is safe.
 */
class ProcessLoyverseWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(ProcessLoyverseReceipt $process): void
    {
        $connection = PosConnection::query()
            ->where('provider', PosProvider::Loyverse)
            ->where('external_merchant_id', (string) ($this->payload['merchant_id'] ?? ''))
            ->where('status', PosConnectionStatus::Connected)
            ->with('property')
            ->first();

        if ($connection === null || ! Feature::for($connection->property)->active(PosIntegration::class)) {
            return;
        }

        foreach ((array) ($this->payload['receipts'] ?? []) as $receipt) {
            if (is_array($receipt)) {
                $process($connection, $receipt);
            }
        }
    }
}

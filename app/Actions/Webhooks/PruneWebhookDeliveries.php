<?php

namespace App\Actions\Webhooks;

use App\Models\WebhookDelivery;

class PruneWebhookDeliveries
{
    /**
     * Delete webhook deliveries older than the retention period, with their tries. Returns how many deliveries were
     * deleted. Running it again finds nothing more to delete.
     */
    public function __invoke(): int
    {
        return WebhookDelivery::query()->where('created_at', '<', now()->subDays(WebhookDelivery::RETENTION_DAYS))->delete();
    }
}

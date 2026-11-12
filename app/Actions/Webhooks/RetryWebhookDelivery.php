<?php

namespace App\Actions\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

class RetryWebhookDelivery
{
    /**
     * Queue one more try of a Refused or Failed delivery, with the same event id and body. Returns false for any other
     * status, or when the endpoint is off.
     */
    public function __invoke(User $admin, WebhookDelivery $delivery): bool
    {
        return DB::transaction(function () use ($admin, $delivery): bool {
            $delivery = WebhookDelivery::query()->with('endpoint')->lockForUpdate()->findOrFail($delivery->id);

            if (! $delivery->status->canTransitionTo(WebhookDeliveryStatus::Pending) || ! $delivery->endpoint->is_active) {
                return false;
            }

            $delivery->update(['status' => WebhookDeliveryStatus::Pending, 'next_attempt_at' => null]);

            activity()
                ->performedOn($delivery)
                ->causedBy($admin)
                ->event('webhook_delivery.retried')
                ->withProperties(['webhook_endpoint_id' => $delivery->webhook_endpoint_id, 'event' => $delivery->event->value])
                ->log('Webhook delivery retried');

            DeliverWebhookJob::dispatch($delivery->id)->afterCommit();

            return true;
        });
    }
}

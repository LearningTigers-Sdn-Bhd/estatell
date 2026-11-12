<?php

namespace App\Actions\Webhooks;

use App\Models\User;
use App\Models\WebhookEndpoint;

class DeleteWebhookEndpoint
{
    /**
     * Delete an endpoint with its events and its delivery log. A try still queued finds no delivery and does nothing.
     */
    public function __invoke(User $admin, WebhookEndpoint $endpoint): void
    {
        activity()
            ->causedBy($admin)
            ->event('webhook_endpoint.deleted')
            ->withProperties(['webhook_endpoint_id' => $endpoint->id, 'name' => $endpoint->name, 'url' => $endpoint->url, 'property_id' => $endpoint->property_id])
            ->log('Webhook endpoint deleted');

        $endpoint->delete();
    }
}

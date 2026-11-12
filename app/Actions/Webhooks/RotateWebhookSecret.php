<?php

namespace App\Actions\Webhooks;

use App\Models\User;
use App\Models\WebhookEndpoint;

class RotateWebhookSecret
{
    /**
     * Replace the signing secret and return the new one. It is shown once. Tries already queued are signed with the new
     * secret when they are sent.
     */
    public function __invoke(User $admin, WebhookEndpoint $endpoint): string
    {
        $secret = SaveWebhookEndpoint::newSecret();
        $endpoint->update(['secret' => $secret]);

        activity()
            ->performedOn($endpoint)
            ->causedBy($admin)
            ->event('webhook_endpoint.secret_rotated')
            ->log('Webhook secret rotated');

        return $secret;
    }
}

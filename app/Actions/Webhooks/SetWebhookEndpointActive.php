<?php

namespace App\Actions\Webhooks;

use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;

class SetWebhookEndpointActive
{
    /**
     * Turn an endpoint on or off. Turning it on clears the failing date and the system's reason for turning it off, so
     * the 3 days start again. Returns false when the endpoint already has that state.
     */
    public function __invoke(User $admin, WebhookEndpoint $endpoint, bool $active): bool
    {
        return DB::transaction(function () use ($admin, $endpoint, $active): bool {
            $endpoint = WebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);

            if ($endpoint->is_active === $active) {
                return false;
            }

            $endpoint->update($active
                ? ['is_active' => true, 'failing_since' => null, 'disabled_at' => null, 'disabled_reason' => null]
                : ['is_active' => false]);

            activity()
                ->performedOn($endpoint)
                ->causedBy($admin)
                ->event($active ? 'webhook_endpoint.activated' : 'webhook_endpoint.deactivated')
                ->log($active ? 'Webhook endpoint turned on' : 'Webhook endpoint turned off');

            return true;
        });
    }
}

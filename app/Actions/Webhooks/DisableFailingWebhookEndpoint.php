<?php

namespace App\Actions\Webhooks;

use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\WebhookEndpointDisabled;
use Illuminate\Support\Facades\Notification;

/**
 * Turn an active endpoint off when it has had no successful delivery for 3 days, and tell every active platform
 * admin. Returns false, and changes nothing, when the endpoint is off already or has not failed long enough.
 */
class DisableFailingWebhookEndpoint
{
    public function __invoke(WebhookEndpoint $endpoint, string $reason): bool
    {
        if (! $endpoint->is_active || $endpoint->failing_since === null) {
            return false;
        }

        if ($endpoint->failing_since->isAfter(now()->subDays(WebhookEndpoint::DISABLE_AFTER_DAYS))) {
            return false;
        }

        $turnedOff = WebhookEndpoint::query()
            ->whereKey($endpoint->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'disabled_at' => now(), 'disabled_reason' => $reason]);

        if ($turnedOff === 0) {
            return false;
        }

        $endpoint->refresh()->loadMissing('property');
        $admins = User::query()->where('is_admin', true)->whereNull('deactivated_at')->get();

        Notification::send($admins, new WebhookEndpointDisabled(
            $endpoint->id,
            $endpoint->name,
            $endpoint->url,
            $endpoint->property?->name,
            $reason,
        ));

        return true;
    }
}

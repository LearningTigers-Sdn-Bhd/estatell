<?php

namespace App\Actions\Webhooks;

use App\Data\Webhooks\WebhookEndpointInputData;
use App\Enums\WebhookEvent;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Add a webhook endpoint or change one: name, URL, property and the events it hears. A new endpoint gets a signing
 * secret, returned once as `$secret` and never shown again. See docs/32-webhooks/01-outgoing-webhooks.md#admin-page.
 */
class SaveWebhookEndpoint
{
    /**
     * @return array{endpoint: WebhookEndpoint, secret: string|null}
     */
    public function __invoke(User $admin, WebhookEndpointInputData $data, ?WebhookEndpoint $endpoint = null): array
    {
        return DB::transaction(function () use ($admin, $data, $endpoint): array {
            $secret = null;

            if ($endpoint === null) {
                $secret = self::newSecret();
                $endpoint = WebhookEndpoint::query()->create([
                    'property_id' => $data->propertyId,
                    'name' => $data->name,
                    'url' => $data->url,
                    'secret' => $secret,
                    'is_active' => $data->isActive,
                ]);
            } else {
                $endpoint = WebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);
                $endpoint->update(['property_id' => $data->propertyId, 'name' => $data->name, 'url' => $data->url]);
            }

            $endpoint->events()->whereNotIn('event', array_map(fn (WebhookEvent $event): string => $event->value, $data->events))->delete();

            foreach ($data->events as $event) {
                $endpoint->events()->firstOrCreate(['event' => $event]);
            }

            activity()
                ->performedOn($endpoint)
                ->causedBy($admin)
                ->event($secret === null ? 'webhook_endpoint.updated' : 'webhook_endpoint.created')
                ->withProperties([
                    'property_id' => $data->propertyId,
                    'url' => $data->url,
                    'events' => array_map(fn (WebhookEvent $event): string => $event->value, $data->events),
                ])
                ->log($secret === null ? 'Webhook endpoint updated' : 'Webhook endpoint added');

            return ['endpoint' => $endpoint, 'secret' => $secret];
        });
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}

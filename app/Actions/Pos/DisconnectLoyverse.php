<?php

namespace App\Actions\Pos;

use App\Enums\PosConnectionStatus;
use App\Models\PosConnection;
use App\Models\User;
use App\Services\Pos\Loyverse\LoyverseClient;
use App\Services\Pos\Loyverse\LoyverseException;

/**
 * Disconnect Loyverse: remove the webhook (best effort), clear the tokens and stop outbound guest syncs. Postings,
 * mappings and guest links stay as history. Returns false, writing nothing, when it is already Disconnected.
 */
class DisconnectLoyverse
{
    public function __construct(private LoyverseClient $client) {}

    public function __invoke(User $actor, PosConnection $connection): bool
    {
        if ($connection->status === PosConnectionStatus::Disconnected) {
            return false;
        }

        if ($connection->external_webhook_id !== null && $connection->access_token !== null) {
            try {
                $this->client->deleteWebhook($connection, $connection->external_webhook_id);
            } catch (LoyverseException) {
                // The token may already be revoked. The webhook then dies with it.
            }
        }

        $connection->forceFill([
            'status' => PosConnectionStatus::Disconnected,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'external_webhook_id' => null,
            'last_error' => null,
        ])->save();

        activity()
            ->performedOn($connection)
            ->causedBy($actor)
            ->event('pos.loyverse_disconnected')
            ->withProperties(['property_id' => $connection->property_id])
            ->log('Loyverse disconnected');

        return true;
    }
}

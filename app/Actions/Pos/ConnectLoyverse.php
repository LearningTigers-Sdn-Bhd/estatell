<?php

namespace App\Actions\Pos;

use App\Enums\PosConnectionStatus;
use App\Enums\PosProvider;
use App\Models\PosConnection;
use App\Models\PosGuestLink;
use App\Models\PosOutletMapping;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\Loyverse\LoyverseClient;
use App\Services\Pos\Loyverse\LoyverseException;
use Illuminate\Support\Facades\Cache;

/**
 * Finish the Loyverse OAuth connect: trade the code for tokens, read the merchant id, save the connection and register
 * the `receipts.update` webhook. A merchant can belong to one property only, because a webhook finds its property by
 * merchant id (`merchant_in_use`). When the webhook cannot be registered the connection is saved as Error with the reason
 * (`webhook_failed`), so staff can connect again. Reconnecting to a different Loyverse account drops the old account's guest
 * links, mappings and cached catalogue. Returns null on success, or a refusal code.
 * See docs/33-pos/04-loyverse-integration.md#oauth-20-flow.
 */
class ConnectLoyverse
{
    public const REFUSAL_MERCHANT_IN_USE = 'merchant_in_use';

    public const REFUSAL_WEBHOOK_FAILED = 'webhook_failed';

    public const REFUSAL_CONNECT_FAILED = 'connect_failed';

    public function __construct(private LoyverseClient $client) {}

    public function __invoke(?User $actor, Property $property, string $code): ?string
    {
        try {
            $tokens = $this->client->exchangeCode($code);
            $merchantId = $this->client->merchantIdFor($tokens['access_token']);
        } catch (LoyverseException) {
            return self::REFUSAL_CONNECT_FAILED;
        }

        $taken = PosConnection::query()
            ->where('provider', PosProvider::Loyverse)
            ->where('external_merchant_id', $merchantId)
            ->where('property_id', '!=', $property->id)
            ->where('status', '!=', PosConnectionStatus::Disconnected)
            ->exists();

        if ($taken) {
            return self::REFUSAL_MERCHANT_IN_USE;
        }

        $connection = PosConnection::query()->where('property_id', $property->id)->where('provider', PosProvider::Loyverse)->first() ?? new PosConnection;
        $connection->property_id = $property->id;

        // A different Loyverse account has other stores, payment types and customers: nothing saved for the old one applies.
        if ($connection->exists && $connection->external_merchant_id !== null && $connection->external_merchant_id !== $merchantId) {
            PosGuestLink::query()->where('pos_connection_id', $connection->id)->delete();
            PosOutletMapping::query()->where('pos_connection_id', $connection->id)->delete();
            Cache::forget('loyverse-catalogue:'.$connection->id);
        }

        $connection->forceFill([
            'provider' => PosProvider::Loyverse,
            'status' => PosConnectionStatus::Connected,
            'external_merchant_id' => $merchantId,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
            'last_error' => null,
        ])->save();

        $refusal = null;

        try {
            $connection->forceFill(['external_webhook_id' => $this->client->ensureReceiptsWebhook($connection, route('webhooks.loyverse'))])->save();
        } catch (LoyverseException $exception) {
            $connection->forceFill(['status' => PosConnectionStatus::Error, 'last_error' => 'The Loyverse webhook could not be registered. '.$exception->getMessage()])->save();
            $refusal = self::REFUSAL_WEBHOOK_FAILED;
        }

        activity()
            ->performedOn($connection)
            ->causedBy($actor)
            ->event('pos.loyverse_connected')
            ->withProperties(['property_id' => $property->id, 'merchant_id' => $merchantId, 'webhook_registered' => $refusal === null])
            ->log('Loyverse connected');

        return $refusal;
    }
}

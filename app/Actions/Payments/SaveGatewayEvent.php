<?php

namespace App\Actions\Payments;

use App\Enums\PaymentMode;
use App\Models\GatewayEvent;
use App\Services\Payments\CurlecGateway;

/**
 * Save a Curlec webhook event once, by its event ID. A repeat returns the first row. A body with no event ID uses its
 * hash, so an identical repeat still saves one row. `$mode` is the mode of the route whose secret signed it.
 */
class SaveGatewayEvent
{
    public function __construct(private CurlecGateway $gateway) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(PaymentMode $mode, ?string $eventId, string $eventType, array $payload, string $rawBody): GatewayEvent
    {
        return GatewayEvent::query()->createOrFirst(
            ['event_id' => filled($eventId) ? $eventId : 'body:'.hash('sha256', $rawBody)],
            ['gateway_account' => $this->gateway->account(), 'mode' => $mode, 'event_type' => $eventType, 'payload' => $payload],
        );
    }
}

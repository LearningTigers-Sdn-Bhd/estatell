<?php

namespace App\Models;

use App\Enums\PaymentMode;
use Carbon\CarbonImmutable;
use Database\Factories\GatewayEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One webhook event from Curlec, saved before it is processed. It has no property, because an event for an order
 * estatell did not create belongs to none. `mode` is the mode of the route that received it, and the event acts only on
 * payments of that mode.
 *
 * @property int $id
 * @property string $gateway_account
 * @property PaymentMode $mode
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['gateway_account', 'mode', 'event_id', 'event_type', 'payload', 'processed_at'])]
class GatewayEvent extends Model
{
    /** @use HasFactory<GatewayEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'mode' => PaymentMode::class,
            'processed_at' => 'immutable_datetime',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One event sent to one endpoint. `payload` is the body exactly as it is sent on every try. See
 * docs/32-webhooks/01-outgoing-webhooks.md.
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property int|null $property_id
 * @property WebhookEvent $event
 * @property string $event_id
 * @property array<string, mixed> $payload
 * @property bool $is_test
 * @property WebhookDeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $error
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['webhook_endpoint_id', 'property_id', 'event', 'event_id', 'payload', 'is_test', 'status', 'attempts', 'response_status', 'error', 'next_attempt_at', 'delivered_at'])]
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    public const int MAX_ATTEMPTS = 5;

    public const int RETENTION_DAYS = 30;

    /**
     * Seconds to wait before tries 2 to 5.
     *
     * @var list<int>
     */
    public const array BACKOFF_SECONDS = [60, 300, 1800, 7200];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => WebhookEvent::class,
            'payload' => 'array',
            'is_test' => 'boolean',
            'status' => WebhookDeliveryStatus::class,
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<WebhookDeliveryAttempt, $this>
     */
    public function tries(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class)->orderBy('attempt');
    }
}

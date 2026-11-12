<?php

namespace App\Data\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use Spatie\LaravelData\Data;

/**
 * One delivery on the delivery Sheet: its tries and the body that was sent.
 */
class WebhookDeliveryData extends Data
{
    public const array RELATIONS = ['endpoint:id,name,is_active', 'tries'];

    /**
     * @param  list<array{attempt: int, responseStatus: int|null, error: string|null, durationMs: int, attemptedAt: string}>  $tries
     */
    public function __construct(
        public WebhookDeliveryRowData $row,
        public string $eventId,
        public ?string $error,
        public ?string $nextAttemptAt,
        public ?string $deliveredAt,
        public array $tries,
        public string $payload,
    ) {}

    public static function fromModel(WebhookDelivery $delivery): self
    {
        return new self(
            row: WebhookDeliveryRowData::fromModel($delivery),
            eventId: $delivery->event_id,
            error: $delivery->error,
            nextAttemptAt: $delivery->next_attempt_at?->format('Y-m-d H:i'),
            deliveredAt: $delivery->delivered_at?->format('Y-m-d H:i'),
            tries: array_values($delivery->tries->map(fn (WebhookDeliveryAttempt $try): array => [
                'attempt' => $try->attempt,
                'responseStatus' => $try->response_status,
                'error' => $try->error,
                'durationMs' => $try->duration_ms,
                'attemptedAt' => $try->attempted_at->format('Y-m-d H:i:s'),
            ])->all()),
            payload: (string) json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}

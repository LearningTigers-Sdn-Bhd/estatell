<?php

namespace App\Services\Webhooks;

use App\Enums\WebhookEvent;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Builds the envelope every webhook body shares. `data` is the event's own shape. Times are in the property's time
 * zone, or UTC when there is no property. See
 * docs/32-webhooks/01-outgoing-webhooks.md#payload.
 */
class WebhookPayload
{
    public const int VERSION = 1;

    public function newEventId(): string
    {
        return 'evt_'.Str::uuid7();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, event: string, version: int, occurred_at: string, test: bool, property: array{code: string, name: string, timezone: string}|null, data: array<string, mixed>}
     */
    public function envelope(string $eventId, WebhookEvent $event, ?Property $property, array $data, CarbonImmutable $occurredAt, bool $isTest = false): array
    {
        $timeZone = $property === null ? 'UTC' : ($property->settings->time_zone ?? config('app.timezone'));

        return [
            'id' => $eventId,
            'event' => $event->value,
            'version' => self::VERSION,
            'occurred_at' => $occurredAt->setTimezone($timeZone)->toIso8601String(),
            'test' => $isTest,
            'property' => $property === null ? null : [
                'code' => $property->code,
                'name' => $property->name,
                'timezone' => $timeZone,
            ],
            'data' => $data,
        ];
    }

    /**
     * The exact bytes that are signed and sent.
     *
     * @param  array<string, mixed>  $payload
     */
    public function body(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

<?php

namespace App\Services\Webhooks\Payloads;

use App\Enums\WebhookEvent;
use App\Models\ChannelAlert;
use Illuminate\Database\Eloquent\Model;

/**
 * A channel manager alert. The raw provider payload is not sent.
 */
class ChannelAlertWebhookPayload implements WebhookPayloadBuilder
{
    public function build(WebhookEvent $event, Model $subject, array $context): ?array
    {
        if (! $subject instanceof ChannelAlert) {
            return null;
        }

        return [
            'alert' => [
                'id' => $subject->id,
                'event' => $subject->event->value,
                'label' => $subject->event->label(),
                'external_booking_id' => $subject->external_booking_id,
                'revision_id' => $subject->revision_id,
                'created_at' => $subject->created_at?->toIso8601String(),
            ],
        ];
    }
}

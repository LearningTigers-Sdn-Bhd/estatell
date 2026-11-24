<?php

namespace App\Services\Webhooks\Payloads;

use App\Enums\WebhookEvent;
use App\Models\AiThread;
use Illuminate\Database\Eloquent\Model;

/**
 * An AI assistant thread that was handed over to staff, with the model's short summary from the context. It never
 * carries the chat itself. See docs/34-ai-assistant/03-places-and-routes.md#handover.
 */
class AiThreadWebhookPayload implements WebhookPayloadBuilder
{
    public function build(WebhookEvent $event, Model $subject, array $context): ?array
    {
        if (! $subject instanceof AiThread) {
            return null;
        }

        $subject->loadMissing('reservationUnit');

        return [
            'thread' => [
                'id' => $subject->id,
                'place' => $subject->place->value,
                'status' => $subject->status->value,
                'reservation_id' => $subject->reservationUnit?->reservation_id,
                'escalated_at' => $subject->escalated_at?->toIso8601String(),
            ],
            'summary' => is_string($context['summary'] ?? null) ? $context['summary'] : null,
        ];
    }
}

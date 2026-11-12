<?php

namespace App\Services\Webhooks\Payloads;

use App\Enums\WebhookEvent;
use App\Models\Room;
use Illuminate\Database\Eloquent\Model;

/**
 * A room and its housekeeping status.
 */
class RoomWebhookPayload implements WebhookPayloadBuilder
{
    public function build(WebhookEvent $event, Model $subject, array $context): ?array
    {
        if (! $subject instanceof Room) {
            return null;
        }

        $subject->loadMissing(['roomType', 'status']);

        return [
            'room' => [
                'id' => $subject->id,
                'number' => $subject->number,
                'room_type' => $subject->roomType->name,
                'housekeeping_status' => $subject->status?->status->value,
                'needs_maintenance' => $subject->status->needs_maintenance ?? false,
                'remark' => $subject->status?->remark,
            ],
        ];
    }
}

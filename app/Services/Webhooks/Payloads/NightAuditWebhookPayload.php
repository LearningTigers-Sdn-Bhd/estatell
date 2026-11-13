<?php

namespace App\Services\Webhooks\Payloads;

use App\Enums\WebhookEvent;
use App\Models\NightAudit;
use Illuminate\Database\Eloquent\Model;

/**
 * A completed night audit and the business date it closed.
 */
class NightAuditWebhookPayload implements WebhookPayloadBuilder
{
    public function build(WebhookEvent $event, Model $subject, array $context): ?array
    {
        if (! $subject instanceof NightAudit) {
            return null;
        }

        $subject->loadMissing('businessDate');

        return [
            'night_audit' => [
                'id' => $subject->id,
                'business_date' => $subject->businessDate->date->toDateString(),
                'status' => $subject->status->value,
                'trigger' => $subject->trigger->value,
                'forced' => $subject->forced,
            ],
        ];
    }
}

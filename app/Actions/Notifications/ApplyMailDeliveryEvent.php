<?php

namespace App\Actions\Notifications;

use App\Enums\MailStatus;
use App\Models\MailEvent;

class ApplyMailDeliveryEvent
{
    /**
     * The row status each provider event type moves a message to. Other event types are ignored.
     *
     * @var array<string, MailStatus>
     */
    private const array STATUS_BY_TYPE = [
        'email.delivered' => MailStatus::Delivered,
        'email.bounced' => MailStatus::Bounced,
        'email.complained' => MailStatus::Complained,
        'email.failed' => MailStatus::Failed,
        'email.suppressed' => MailStatus::Suppressed,
    ];

    /**
     * Apply a delivery webhook event to the mail log row with this provider message id. Returns true when the row
     * changed. An unknown type, an unknown message id, a repeated event and a late event all change nothing.
     */
    public function __invoke(string $type, string $providerMessageId, ?string $reason = null): bool
    {
        $status = self::STATUS_BY_TYPE[$type] ?? null;

        if ($status === null) {
            return false;
        }

        $event = MailEvent::query()->where('provider_message_id', $providerMessageId)->latest('sent_at')->first();

        if ($event === null || ! $event->status->canTransitionTo($status)) {
            return false;
        }

        $event->update(['status' => $status, 'error' => $reason ?? $event->error]);

        return true;
    }
}

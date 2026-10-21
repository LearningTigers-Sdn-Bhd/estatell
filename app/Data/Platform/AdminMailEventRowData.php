<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Models\MailEvent;
use Spatie\LaravelData\Data;

class AdminMailEventRowData extends Data
{
    public function __construct(
        public int $id,
        public string $sentAt,
        public string $recipient,
        public int $recipientCount,
        public string $subject,
        public ?string $notificationType,
        public BadgeData $status,
        public ?string $providerMessageId,
        public ?string $error,
        public ?string $propertyName,
    ) {}

    /**
     * Expects the `property` relation loaded.
     */
    public static function fromModel(MailEvent $event): self
    {
        return new self(
            id: $event->id,
            sentAt: $event->sent_at->format('Y-m-d H:i'),
            recipient: $event->recipient,
            recipientCount: $event->recipient_count,
            subject: $event->subject,
            notificationType: $event->notification_type,
            status: BadgeData::fromMailStatus($event->status),
            providerMessageId: $event->provider_message_id,
            error: $event->error,
            propertyName: $event->property?->name,
        );
    }
}

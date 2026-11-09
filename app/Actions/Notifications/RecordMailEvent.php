<?php

namespace App\Actions\Notifications;

use App\Enums\MailStatus;
use App\Models\MailEvent;
use App\Models\User;
use App\Services\Notifications\MailActorResolver;
use Symfony\Component\Mime\Email;

class RecordMailEvent
{
    public function __construct(private MailActorResolver $actors) {}

    /**
     * Write one mail log row for a send attempt. The recipient is the first `to` address and the body is never kept.
     * `$notificationType` is the notification class, when the mail came from one.
     */
    public function __invoke(Email $email, MailStatus $status, ?string $notificationType = null, ?string $providerMessageId = null, ?string $error = null): MailEvent
    {
        $recipients = $email->getTo();
        $recipient = ($recipients[0] ?? null)?->getAddress() ?? '';
        $actor = $this->actors->current();

        return MailEvent::query()->create([
            'property_id' => $actor['property_id'] ?? ($recipient === '' ? null : User::query()->where('email', $recipient)->value('property_id')),
            'actor_type' => $actor['type'],
            'actor_user_id' => $actor['user_id'],
            'notification_type' => $notificationType === null ? null : class_basename($notificationType),
            'recipient' => $recipient,
            'recipient_count' => count($recipients),
            'subject' => (string) $email->getSubject(),
            'status' => $status,
            'provider_message_id' => $providerMessageId,
            'error' => $error,
            'sent_at' => now(),
        ]);
    }
}

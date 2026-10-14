<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a property's owner that platform support viewed the property as its staff. Sent when the session ends, so
 * the duration is known. The data is copied in, because the session is gone by the time the message is built.
 */
class SupportAccessUsed extends PropertyNotification
{
    public function __construct(
        public string $supportUserName,
        public ?string $partnerName,
        public string $reason,
        public ?string $note,
        public CarbonImmutable $startedAt,
        public int $minutes,
    ) {
        parent::__construct(NotificationType::SupportAccessUsed);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->line($this->who())
            ->line(__('Started :time.', ['time' => $this->startedAt->toDayDateTimeString()]))
            ->line(__('Reason: :reason.', ['reason' => $this->reason]))
            ->line(__('Duration: :minutes min.', ['minutes' => $this->minutes]));

        if ($this->note !== null) {
            $mail->line(__('Note: :note', ['note' => $this->note]));
        }

        return $mail->line(__('You can switch support access off in your property settings.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title(),
            'body' => $this->who(),
            'support_user_name' => $this->supportUserName,
            'partner_name' => $this->partnerName,
            'reason' => $this->reason,
            'note' => $this->note,
            'started_at' => $this->startedAt->toIso8601String(),
            'minutes' => $this->minutes,
        ];
    }

    private function title(): string
    {
        return __('Support viewed your property');
    }

    private function who(): string
    {
        return $this->partnerName === null
            ? __(':name from estatell support viewed your property as a member of your staff.', ['name' => $this->supportUserName])
            : __(':name from :partner viewed your property as a member of your staff.', ['name' => $this->supportUserName, 'partner' => $this->partnerName]);
    }
}

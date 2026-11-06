<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells staff with `payment.refund` that an online refund needs attention. A late payment has no reservation, so the link is
 * optional. See docs/04-notifications/01-notification-types.md.
 */
class OnlineRefundNeedsAttention extends PropertyNotification
{
    public function __construct(
        public string $propertyCode,
        public string $propertyName,
        public ?int $reservationId,
        public ?string $reference,
        public string $problem,
    ) {
        parent::__construct(NotificationType::OnlineRefundNeedsAttention);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => $this->title(),
                'propertyName' => $this->propertyName,
            ])
            ->subject($this->title())
            ->line($this->problem);

        return $this->reservationId === null ? $mail : $mail->action(__('Open folio'), (string) $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title(),
            'body' => $this->problem,
            'reservation_id' => $this->reservationId,
            'property_code' => $this->propertyCode,
            'url' => $this->url(),
        ];
    }

    private function title(): string
    {
        return $this->reference === null
            ? __('An online refund needs attention')
            : __('An online refund on :reference needs attention', ['reference' => $this->reference]);
    }

    private function url(): ?string
    {
        return $this->reservationId === null ? null : route('reservations.folio', ['property' => $this->propertyCode, 'reservation' => $this->reservationId]);
    }
}

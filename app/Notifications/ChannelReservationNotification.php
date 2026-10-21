<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The shared body of the two channel booking messages to reservation staff. The data is copied in, so the message does
 * not depend on the reservation when it is built. See docs/04-notifications/01-notification-types.md.
 */
abstract class ChannelReservationNotification extends PropertyNotification
{
    public function __construct(
        NotificationType $type,
        public int $reservationId,
        public string $reference,
        public string $propertyCode,
        public string $propertyName,
        public string $channelName,
        public string $arrivalDate,
    ) {
        parent::__construct($type);
    }

    abstract protected function title(): string;

    abstract protected function body(): string;

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => $this->title(),
                'propertyName' => $this->propertyName,
            ])
            ->subject($this->title())
            ->line($this->body())
            ->action(__('Open reservation'), $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'reservation_id' => $this->reservationId,
            'property_code' => $this->propertyCode,
            'url' => $this->url(),
        ];
    }

    protected function url(): string
    {
        return route('reservations.show', ['property' => $this->propertyCode, 'reservation' => $this->reservationId]);
    }
}

<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells reservation staff that a guest booked and paid online. The data is copied in, so the message does not depend on
 * the reservation when it is built. See docs/04-notifications/01-notification-types.md.
 */
class OnlineBookingReceived extends PropertyNotification
{
    public function __construct(
        public int $reservationId,
        public string $reference,
        public string $propertyCode,
        public string $propertyName,
        public string $guestName,
        public string $arrivalDate,
    ) {
        parent::__construct(NotificationType::OnlineBookingReceived);
    }

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

    private function title(): string
    {
        return __('Online booking :reference', ['reference' => $this->reference]);
    }

    private function body(): string
    {
        return __(':guest booked :reference online and paid. Arrival: :date.', ['guest' => $this->guestName, 'reference' => $this->reference, 'date' => $this->arrivalDate]);
    }

    private function url(): string
    {
        return route('reservations.show', ['property' => $this->propertyCode, 'reservation' => $this->reservationId]);
    }
}

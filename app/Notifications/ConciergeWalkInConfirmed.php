<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms a walk-in booking made on the Concierge page, once its first payment is in: the booking number, the room and the
 * date, and the link to check in on the guest's own phone. A guest is not a user, so it goes by mail to the address typed on the
 * ID step and skips the preference matrix. The data is copied in, so the message does not depend on the stay when it is built.
 * See docs/04-notifications/01-notification-types.md.
 */
class ConciergeWalkInConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $propertyName,
        public string $guestName,
        public string $reference,
        public string $shortCode,
        public string $roomType,
        public string $arrivalDate,
        public ?string $departureDate,
        public ?string $arrivalTime,
        public string $stayUrl,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dayUse = $this->departureDate === null;

        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('Your booking at :property is confirmed', ['property' => $this->propertyName]),
                'propertyName' => $this->propertyName,
                'mailDetails' => array_filter([
                    __('Reference') => $this->reference,
                    __('Booking code') => $this->shortCode,
                    __('Room') => $this->roomType,
                    $dayUse ? __('Date') : __('Check-in') => $this->arrivalDate,
                    $dayUse ? __('From') : __('Check-out') => $dayUse ? $this->arrivalTime : $this->departureDate,
                ]),
            ])
            ->subject(__('Your booking at :property is confirmed', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $this->guestName]))
            ->line(__('Thank you for booking with :property. Your payment is received.', ['property' => $this->propertyName]))
            ->line(__('Use the link below to check in on your phone. Keep this email: your booking code is on it.'))
            ->action(__('Check in'), $this->stayUrl);
    }
}

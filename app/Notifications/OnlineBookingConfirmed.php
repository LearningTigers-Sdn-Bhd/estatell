<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a guest that their online booking is confirmed, with the Manage booking link. A guest is not a user, so it goes by
 * mail to the address on the hold and skips the preference matrix. The data is copied in, so the message does not depend
 * on the reservation when it is built. See docs/04-notifications/01-notification-types.md.
 */
class OnlineBookingConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $rooms
     */
    public function __construct(
        public string $reference,
        public string $shortCode,
        public string $propertyName,
        public string $guestName,
        public string $arrivalDate,
        public string $departureDate,
        public array $rooms,
        public string $amountPaid,
        public string $manageUrl,
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
        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('Your booking at :property is confirmed', ['property' => $this->propertyName]),
                'propertyName' => $this->propertyName,
                'mailDetails' => [
                    __('Reference') => $this->reference,
                    __('Booking code') => $this->shortCode,
                    __('Check-in') => $this->arrivalDate,
                    __('Check-out') => $this->departureDate,
                    __('Rooms') => implode(', ', $this->rooms),
                    __('Paid') => $this->amountPaid,
                ],
            ])
            ->subject(__('Your booking at :property is confirmed', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $this->guestName]))
            ->line(__('Thank you for booking with :property. Your payment is received and your rooms are held for you.', ['property' => $this->propertyName]))
            ->line(__('Use the link below to view your booking or to cancel it.'))
            ->action(__('Manage booking'), $this->manageUrl);
    }
}

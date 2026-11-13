<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Gives a guest who has checked in the link to their stay page: requests, the bill, documents and check-out. A guest is not
 * a user, so it goes by mail to the address on the booking and skips the preference matrix. The data is copied in, so the
 * message does not depend on the stay when it is built. See docs/04-notifications/01-notification-types.md.
 */
class ConciergeStayLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $propertyName,
        public string $guestName,
        public string $arrivalDate,
        public string $departureDate,
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
        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('Your stay at :property', ['property' => $this->propertyName]),
                'propertyName' => $this->propertyName,
                'mailDetails' => [
                    __('Check-in') => $this->arrivalDate,
                    __('Check-out') => $this->departureDate,
                ],
            ])
            ->subject(__('Your stay at :property: your link', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $this->guestName]))
            ->line(__('Welcome to :property. Use the link below during your stay to ask the front desk for something, see your bill and documents, and check out.', ['property' => $this->propertyName]))
            ->action(__('Open my stay'), $this->stayUrl)
            ->line(__('Keep this email. Anyone who has the link can open your stay page.'));
    }
}

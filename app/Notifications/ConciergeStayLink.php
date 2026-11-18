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
        public ?string $roomNumber = null,
        public ?string $pinCode = null,
        public ?string $pinValidUntil = null,
        public bool $pinOnStayPage = false,
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
                'mailDetails' => array_filter([
                    __('Check-in') => $this->arrivalDate,
                    __('Check-out') => $this->departureDate,
                    __('Room') => $this->roomNumber,
                    __('Door PIN') => $this->pinCode,
                    __('PIN valid until') => $this->pinCode === null ? null : $this->pinValidUntil,
                ]),
            ])
            ->subject($this->pinCode === null
                ? __('Your stay at :property: your link', ['property' => $this->propertyName])
                : __('Your stay at :property: your door PIN and link', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $this->guestName]))
            ->line(__('Welcome to :property. Use the link below during your stay to ask the front desk for something, see your bill and documents, and check out.', ['property' => $this->propertyName]))
            ->when($this->pinOnStayPage, fn (MailMessage $mail) => $mail->line(__('Your door PIN is not ready yet. It will show on your stay page as soon as it is.')))
            ->action(__('Open my stay'), $this->stayUrl)
            ->line(__('Keep this email. Anyone who has the link can open your stay page.'));
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Thanks a guest for staying, once they are checked out, from the front desk or from the Concierge page: the dates and, when the
 * property has the Concierge page on, the link to the stay page where the final invoice and the receipts are. A guest is not a user,
 * so it goes by mail to the address on the stay and skips the preference matrix. The data is copied in, so the message does not
 * depend on the stay when it is built. See docs/04-notifications/01-notification-types.md.
 */
class StayCheckedOutThanks extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $propertyName,
        public string $guestName,
        public string $reference,
        public string $arrivalDate,
        public string $checkedOutDate,
        public ?string $stayUrl = null,
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
        $mail = (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('Thank you for staying at :property', ['property' => $this->propertyName]),
                'propertyName' => $this->propertyName,
                'mailDetails' => [
                    __('Reference') => $this->reference,
                    __('Check-in') => $this->arrivalDate,
                    __('Check-out') => $this->checkedOutDate,
                ],
            ])
            ->subject(__('Thank you for staying at :property', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $this->guestName]))
            ->line(__('You are checked out. Thank you for staying with :property. We hope to see you again.', ['property' => $this->propertyName]));

        if ($this->stayUrl !== null) {
            $mail->line(__('Your final invoice and receipts are on your stay page.'))->action(__('View your invoice and receipts'), $this->stayUrl);
        }

        return $mail;
    }
}

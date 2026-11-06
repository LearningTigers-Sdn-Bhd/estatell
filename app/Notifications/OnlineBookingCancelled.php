<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a guest that their online booking is cancelled, with the fee and the refund. A guest is not a user, so it goes by
 * mail to the booker's address and skips the preference matrix. See docs/04-notifications/01-notification-types.md.
 */
class OnlineBookingCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $reference,
        public string $propertyName,
        public string $guestName,
        public string $arrivalDate,
        public string $fee,
        public string $refund,
        public bool $hasRefund,
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
        $message = (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('Your booking at :property is cancelled', ['property' => $this->propertyName]),
                'propertyName' => $this->propertyName,
                'mailDetails' => [
                    __('Reference') => $this->reference,
                    __('Check-in') => $this->arrivalDate,
                    __('Cancellation fee') => $this->fee,
                    __('Refund') => $this->refund,
                ],
            ])
            ->subject(__('Your booking at :property is cancelled', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $this->guestName]))
            ->line(__('Your booking is cancelled and your rooms are released.'));

        return $this->hasRefund
            ? $message->line(__('We are refunding :amount. FPX and e-wallet refunds usually take up to 3 days, and card refunds 5 to 10 days.', ['amount' => $this->refund]))
            : $message->line(__('The cancellation fee covers the amount you paid, so there is no refund.'));
    }
}

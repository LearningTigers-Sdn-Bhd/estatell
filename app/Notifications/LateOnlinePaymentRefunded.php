<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a guest that their payment arrived after the hold expired, the rooms are taken, and the money is on its way back. A
 * guest is not a user, so it goes by mail to the address on the hold. See docs/29-booking-engine/04-payment.md#paid-after-the-hold-expired.
 */
class LateOnlinePaymentRefunded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $propertyName,
        public string $guestName,
        public string $amount,
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
                'mailTitle' => __('Your payment to :property is being refunded', ['property' => $this->propertyName]),
                'propertyName' => $this->propertyName,
                'mailDetails' => [__('Refund') => $this->amount],
            ])
            ->subject(__('Your payment to :property is being refunded', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $this->guestName]))
            ->line(__('Your payment reached us after your rooms were released, and they are no longer available. No booking was made.'))
            ->line(__('We are refunding the whole payment. FPX and e-wallet refunds usually take up to 3 days, and card refunds 5 to 10 days.'));
    }
}

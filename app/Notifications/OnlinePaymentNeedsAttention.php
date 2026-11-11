<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells platform admins that a guest paid online and the booking could not be made. Platform users have no property, so
 * it does not use the property notification preferences. It holds no guest details.
 */
class OnlinePaymentNeedsAttention extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $propertyName,
        public string $propertyCode,
        public string $orderId,
        public ?string $paymentId,
        public string $amount,
        public string $problem,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('An online payment needs attention'),
                'mailDetails' => [
                    __('Property') => $this->propertyName,
                    __('Code') => $this->propertyCode,
                    __('Amount') => $this->amount,
                    __('Curlec order') => $this->orderId,
                    __('Curlec payment') => $this->paymentId ?? '-',
                ],
            ])
            ->subject(__('An online payment at :property needs attention', ['property' => $this->propertyName]))
            ->line($this->problem);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'online_payment_needs_attention',
            'title' => __('An online payment at :property needs attention', ['property' => $this->propertyName]),
            'body' => $this->problem,
            'property_code' => $this->propertyCode,
            'order_id' => $this->orderId,
        ];
    }
}

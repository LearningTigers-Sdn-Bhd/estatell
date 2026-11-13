<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells platform admins that the system turned a webhook endpoint off after 3 days of failed deliveries. Platform
 * users have no property, so it does not use the property notification preferences. It holds no guest details.
 */
class WebhookEndpointDisabled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $endpointId,
        public string $endpointName,
        public string $url,
        public ?string $propertyName,
        public string $reason,
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
                'mailTitle' => __('A webhook endpoint was turned off'),
                'mailDetails' => [
                    __('Endpoint') => $this->endpointName,
                    __('URL') => $this->url,
                    __('Property') => $this->propertyName ?? __('All properties'),
                    __('Reason') => $this->reason,
                ],
            ])
            ->subject(__('Webhook ":name" was turned off', ['name' => $this->endpointName]))
            ->line(__('No delivery to this endpoint succeeded for :days days, so Estatell stopped sending events to it. Check the URL, then turn it on again on the Webhooks page.', ['days' => 3]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'webhook_endpoint_disabled',
            'title' => __('Webhook ":name" was turned off', ['name' => $this->endpointName]),
            'body' => $this->reason,
            'webhook_endpoint_id' => $this->endpointId,
        ];
    }
}

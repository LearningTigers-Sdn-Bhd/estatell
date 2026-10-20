<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells platform admins, and the partner of a property, that an owner sent a property for go-live review. Platform
 * users have no property, so it does not use the property notification preferences. The data is copied in.
 */
class GoLiveRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $propertyName, public string $propertyCode, public string $submittedBy)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __(':property is waiting for approval', ['property' => $this->propertyName]),
                'mailDetails' => [__('Property') => $this->propertyName, __('Code') => $this->propertyCode, __('Sent by') => $this->submittedBy],
            ])
            ->subject(__(':property is waiting for go-live approval', ['property' => $this->propertyName]))
            ->line(__(':name sent :property for go-live approval.', ['name' => $this->submittedBy, 'property' => $this->propertyName]))
            ->action(__('Review setup'), $this->reviewUrl($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'go_live_requested',
            'title' => __(':property is waiting for approval', ['property' => $this->propertyName]),
            'body' => __(':name sent :property for go-live approval.', ['name' => $this->submittedBy, 'property' => $this->propertyName]),
            'property_code' => $this->propertyCode,
        ];
    }

    /**
     * @param  User  $notifiable
     */
    private function reviewUrl(object $notifiable): string
    {
        return $notifiable->is_admin
            ? route('admin.properties.go-live.show', ['property' => $this->propertyCode])
            : route('partner.properties.show', ['code' => $this->propertyCode]);
    }
}

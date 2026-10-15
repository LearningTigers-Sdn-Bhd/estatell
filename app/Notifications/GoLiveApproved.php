<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the owner that estatell approved the go-live request. The mail links to the dashboard, where the You're live
 * card shows once.
 */
class GoLiveApproved extends PropertyNotification
{
    public function __construct(public string $propertyName, public string $propertyCode)
    {
        parent::__construct(NotificationType::GoLiveApproved);
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __(':property is live', ['property' => $this->propertyName]),
                'propertyName' => $this->propertyName,
            ])
            ->subject(__(':property is live', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('estatell approved your setup. :property is open and your first business day has started.', ['property' => $this->propertyName]))
            ->action(__('Open dashboard'), route('dashboard', ['property' => $this->propertyCode]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => __(':property is live', ['property' => $this->propertyName]),
            'body' => __('estatell approved your setup.'),
            'property_code' => $this->propertyCode,
        ];
    }
}

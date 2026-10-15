<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the owner that estatell sent the go-live request back, with the admin's note.
 */
class GoLiveRejected extends PropertyNotification
{
    public function __construct(public string $note)
    {
        parent::__construct(NotificationType::GoLiveRejected);
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $property = $notifiable->property;

        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('Your setup needs changes'),
                'propertyName' => $property->name,
            ])
            ->subject(__(':property needs a few changes before it goes live', ['property' => $property->name]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('estatell reviewed your setup and sent it back.'))
            ->line(__('Note: :note', ['note' => $this->note]))
            ->action(__('Review setup'), route('onboarding.go-live.show', ['property' => $property->code]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => __('Your setup needs changes'),
            'body' => $this->note,
        ];
    }
}

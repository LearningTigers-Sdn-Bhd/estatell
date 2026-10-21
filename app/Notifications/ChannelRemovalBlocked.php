<?php

namespace App\Notifications;

use App\Enums\ChannelRemovalKind;
use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the staff member who archived or unlinked something that it could not be done, because an OTA still uses it at
 * the channel manager. The estatell team has the same news as an alert on the Channels tab. See
 * docs/04-notifications/01-notification-types.md.
 */
class ChannelRemovalBlocked extends PropertyNotification
{
    public function __construct(public ChannelRemovalKind $kind, public string $name, public string $propertyCode, public string $propertyName)
    {
        parent::__construct(NotificationType::ChannelRemovalBlocked);
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', ['mailTitle' => $this->title(), 'propertyName' => $this->propertyName])
            ->subject($this->title())
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($this->body())
            ->action(__('Open settings'), $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type->value, 'title' => $this->title(), 'body' => $this->body(), 'property_code' => $this->propertyCode, 'url' => $this->url()];
    }

    private function title(): string
    {
        return __(':name was not archived', ['name' => $this->name]);
    }

    private function body(): string
    {
        return __(':name is still used by an OTA, so it stays as it is. The estatell team has been told and will remove it from the channels.', ['name' => $this->name]);
    }

    private function url(): string
    {
        return $this->kind === ChannelRemovalKind::RoomType
            ? route('settings.room-types.index', ['property' => $this->propertyCode])
            : route('settings.rate-plans.index', ['property' => $this->propertyCode]);
    }
}

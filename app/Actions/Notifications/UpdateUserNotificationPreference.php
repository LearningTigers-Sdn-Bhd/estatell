<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;

class UpdateUserNotificationPreference
{
    public function __construct(private NotificationPreferences $notificationPreferences) {}

    public function __invoke(User $user, NotificationType $type, NotificationChannel $channel, bool $enabled): bool
    {
        if ($user->is_admin || $user->property === null) {
            return false;
        }

        if (! in_array($channel, $type->channels(), true) || ! $type->canOptOut($channel)) {
            return false;
        }

        if (! $this->notificationPreferences->propertyEnabled($user->property, $type, $channel)) {
            return false;
        }

        if ($enabled === $type->defaultEnabled($channel)) {
            $user->notificationPreferences()->where('type', $type)->where('channel', $channel)->delete();

            return true;
        }

        $user->notificationPreferences()->updateOrCreate(
            ['type' => $type, 'channel' => $channel],
            ['enabled' => $enabled],
        );

        return true;
    }
}

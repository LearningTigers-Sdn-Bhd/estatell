<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Property;
use App\Models\PropertyNotificationSetting;
use App\Models\User;
use App\Models\UserNotificationPreference;

class NotificationPreferences
{
    /**
     * Resolve the enabled Laravel notification channels for a user.
     *
     * @return list<string>
     */
    public function channelsFor(User $user, NotificationType $type): array
    {
        if ($user->is_admin || $user->property === null) {
            return [];
        }

        $user->loadMissing(['notificationPreferences', 'property.notificationSettings']);

        return array_values(array_map(
            fn (NotificationChannel $channel): string => $channel->value,
            array_filter($type->channels(), fn (NotificationChannel $channel): bool => $this->userEnabled($user, $type, $channel)),
        ));
    }

    public function propertyEnabled(Property $property, NotificationType $type, NotificationChannel $channel): bool
    {
        if (! in_array($channel, $type->channels(), true)) {
            return false;
        }

        if (! $type->canOptOut($channel)) {
            return true;
        }

        $property->loadMissing('notificationSettings');

        $setting = $property->notificationSettings->first(
            fn (PropertyNotificationSetting $setting): bool => $setting->type === $type && $setting->channel === $channel,
        );

        return $setting->enabled ?? $type->defaultEnabled($channel);
    }

    public function userEnabled(User $user, NotificationType $type, NotificationChannel $channel): bool
    {
        if ($user->is_admin || $user->property === null) {
            return false;
        }

        if (! $this->propertyEnabled($user->property, $type, $channel)) {
            return false;
        }

        if (! $type->canOptOut($channel)) {
            return true;
        }

        $user->loadMissing('notificationPreferences');

        $preference = $user->notificationPreferences->first(
            fn (UserNotificationPreference $preference): bool => $preference->type === $type && $preference->channel === $channel,
        );

        return $preference->enabled ?? $type->defaultEnabled($channel);
    }
}

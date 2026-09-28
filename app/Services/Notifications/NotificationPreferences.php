<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\User;

class NotificationPreferences
{
    /**
     * Resolve the enabled Laravel notification channels for a user.
     *
     * @return list<string>
     */
    public function channelsFor(User $user, NotificationType $type): array
    {
        return array_values(array_map(
            fn (NotificationChannel $channel): string => $channel->value,
            array_filter(
                $type->channels(),
                fn (NotificationChannel $channel): bool => $type->defaultEnabled($channel),
            ),
        ));
    }
}

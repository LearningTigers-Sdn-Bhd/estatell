<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Property;

class UpdatePropertyNotificationSetting
{
    public function __invoke(Property $property, NotificationType $type, NotificationChannel $channel, bool $enabled): bool
    {
        if (! in_array($channel, $type->channels(), true) || ! $type->canOptOut($channel)) {
            return false;
        }

        if ($enabled === $type->defaultEnabled($channel)) {
            $property->notificationSettings()->where('type', $type)->where('channel', $channel)->delete();

            return true;
        }

        $property->notificationSettings()->updateOrCreate(
            ['type' => $type, 'channel' => $channel],
            ['enabled' => $enabled],
        );

        return true;
    }
}

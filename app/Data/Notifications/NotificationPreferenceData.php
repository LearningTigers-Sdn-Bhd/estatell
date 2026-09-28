<?php

namespace App\Data\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Property;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Spatie\LaravelData\Data;

class NotificationPreferenceData extends Data
{
    /**
     * @param  list<NotificationChannelPreferenceData>  $channels
     */
    public function __construct(
        public NotificationType $type,
        public string $label,
        public array $channels,
    ) {}

    /** @return list<self> */
    public static function forProperty(Property $property, NotificationPreferences $preferences): array
    {
        $property->loadMissing('notificationSettings');

        return array_map(fn (NotificationType $type): self => new self(
            type: $type,
            label: $type->label(),
            channels: array_map(function (NotificationChannel $channel) use ($property, $preferences, $type): NotificationChannelPreferenceData {
                $supported = in_array($channel, $type->channels(), true);
                $editable = $supported && $type->canOptOut($channel);

                return new NotificationChannelPreferenceData(
                    channel: $channel,
                    label: $channel->label(),
                    supported: $supported,
                    enabled: $preferences->propertyEnabled($property, $type, $channel),
                    editable: $editable,
                    disabledReason: self::disabledReason($supported, $editable),
                );
            }, NotificationChannel::cases()),
        ), NotificationType::cases());
    }

    /** @return list<self> */
    public static function forUser(User $user, NotificationPreferences $preferences): array
    {
        $user->loadMissing(['notificationPreferences', 'property.notificationSettings']);

        return array_map(fn (NotificationType $type): self => new self(
            type: $type,
            label: $type->label(),
            channels: array_map(function (NotificationChannel $channel) use ($preferences, $type, $user): NotificationChannelPreferenceData {
                $supported = in_array($channel, $type->channels(), true);
                $mandatory = $supported && ! $type->canOptOut($channel);
                $propertyEnabled = $user->property !== null && $preferences->propertyEnabled($user->property, $type, $channel);
                $editable = $supported && ! $mandatory && $propertyEnabled;

                return new NotificationChannelPreferenceData(
                    channel: $channel,
                    label: $channel->label(),
                    supported: $supported,
                    enabled: $preferences->userEnabled($user, $type, $channel),
                    editable: $editable,
                    disabledReason: ! $supported ? __('Not available') : ($mandatory ? __('Required') : (! $propertyEnabled ? __('Disabled by property') : null)),
                );
            }, NotificationChannel::cases()),
        ), NotificationType::cases());
    }

    private static function disabledReason(bool $supported, bool $editable): ?string
    {
        if (! $supported) {
            return __('Not available');
        }

        return $editable ? null : __('Required');
    }
}

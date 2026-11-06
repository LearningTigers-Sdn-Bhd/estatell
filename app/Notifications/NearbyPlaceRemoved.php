<?php

namespace App\Notifications;

use App\Enums\NotificationType;

/**
 * Tells a property's owner, in the app only, that platform admins rejected a nearby place and it was removed from the
 * property's list.
 */
class NearbyPlaceRemoved extends PropertyNotification
{
    public function __construct(public string $placeName, public string $propertyName)
    {
        parent::__construct(NotificationType::NearbyPlaceRemoved);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => __('Nearby place removed'),
            'body' => __(':place was removed from your nearby places.', ['place' => $this->placeName]),
            'place_name' => $this->placeName,
            'property_name' => $this->propertyName,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Property;
use App\Models\PropertyNotificationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyNotificationSetting>
 */
class PropertyNotificationSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'type' => NotificationType::NightAuditCompleted,
            'channel' => NotificationChannel::Mail,
            'enabled' => false,
        ];
    }
}

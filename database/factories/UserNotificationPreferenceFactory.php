<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserNotificationPreference>
 */
class UserNotificationPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => NotificationType::NightAuditCompleted,
            'channel' => NotificationChannel::Mail,
            'enabled' => false,
        ];
    }
}

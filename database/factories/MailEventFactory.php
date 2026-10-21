<?php

namespace Database\Factories;

use App\Enums\MailStatus;
use App\Models\MailEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailEvent>
 */
class MailEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => null,
            'notification_type' => null,
            'recipient' => fake()->unique()->safeEmail(),
            'recipient_count' => 1,
            'subject' => fake()->sentence(4),
            'status' => MailStatus::Sent,
            'provider_message_id' => fake()->uuid(),
            'error' => null,
            'sent_at' => now(),
        ];
    }

    public function failed(string $error = 'MillionSend 422: validation_error'): static
    {
        return $this->state(['status' => MailStatus::Failed, 'provider_message_id' => null, 'error' => $error]);
    }

    public function suppressed(): static
    {
        return $this->state(['status' => MailStatus::Suppressed, 'provider_message_id' => null]);
    }
}

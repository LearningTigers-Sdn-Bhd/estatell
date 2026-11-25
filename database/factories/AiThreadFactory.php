<?php

namespace Database\Factories;

use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use App\Models\AiThread;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiThread>
 */
class AiThreadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'place' => AiPlace::ConciergeLobby,
            'status' => AiThreadStatus::Open,
            'agent_conversation_id' => (string) Str::uuid7(),
            'last_message_at' => now(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\AiOutcome;
use App\Enums\AiPlace;
use App\Models\AiThread;
use App\Models\AiTurn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiTurn>
 */
class AiTurnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => fn (array $attributes): int => (int) AiThread::query()->whereKey($attributes['ai_thread_id'])->value('property_id'),
            'ai_thread_id' => AiThread::factory(),
            'place' => AiPlace::ConciergeLobby,
            'model' => 'test-model',
            'input_tokens' => 1000,
            'output_tokens' => 100,
            'cached_input_tokens' => 0,
            'cost_micros' => 1000,
            'outcome' => AiOutcome::Answered,
            'duration_ms' => 800,
            'created_at' => now(),
        ];
    }
}

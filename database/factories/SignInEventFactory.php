<?php

namespace Database\Factories;

use App\Enums\SignInResult;
use App\Models\SignInEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignInEvent>
 */
class SignInEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'result' => SignInResult::Succeeded,
            'ip_address' => fake()->ipv4(),
            'country_code' => 'MY',
            'os' => 'Windows',
            'browser' => 'Chrome',
            'occurred_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(['result' => SignInResult::Failed]);
    }
}

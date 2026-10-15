<?php

namespace Database\Factories;

use App\Enums\OnboardingStep;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingSkip>
 */
class OnboardingSkipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory()->onboarding(),
            'step' => OnboardingStep::Companies,
            'skipped_by' => User::factory(),
            'skipped_at' => now(),
        ];
    }
}

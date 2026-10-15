<?php

namespace Database\Factories;

use App\Enums\GoLiveDecision;
use App\Models\GoLiveReview;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoLiveReview>
 */
class GoLiveReviewFactory extends Factory
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
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ];
    }

    public function decided(GoLiveDecision $decision, ?string $note = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'decision' => $decision,
            'note' => $note,
            'decided_by' => User::factory()->admin(),
            'decided_at' => now(),
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\ConciergeQuote;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConciergeQuote> */
class ConciergeQuoteFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'token' => Str::random(40),
            'room_type_id' => fn (array $attributes): int => RoomType::factory()->create(['property_id' => $attributes['property_id']])->id,
            'rate_plan_id' => fn (array $attributes): int => RatePlan::factory()->create(['property_id' => $attributes['property_id']])->id,
            'arrival_date' => now()->addDays(20)->toDateString(),
            'departure_date' => now()->addDays(22)->toDateString(),
            'adults' => 2,
            'quoted_total' => '200.00',
            'expires_at' => now()->addHours(24),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\PosPostingStatus;
use App\Enums\PosPostingType;
use App\Models\PosOutlet;
use App\Models\PosPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosPosting>
 */
class PosPostingFactory extends Factory
{
    /**
     * A pending-review charge of 50.00 with no room.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pos_outlet_id' => PosOutlet::factory(),
            'property_id' => fn (array $attributes): int => PosOutlet::query()->findOrFail((int) $attributes['pos_outlet_id'])->property_id,
            'external_receipt_id' => fake()->unique()->bothify('rcpt_########'),
            'external_receipt_number' => (string) fake()->unique()->numberBetween(1000, 99999),
            'posting_type' => PosPostingType::Charge,
            'status' => PosPostingStatus::PendingReview,
            'amount' => '50.00',
            'failure_reason' => 'room_not_matched',
            'raw_payload' => ['items' => [['name' => 'Nasi Lemak', 'qty' => 1, 'amount' => '50.00']]],
        ];
    }
}

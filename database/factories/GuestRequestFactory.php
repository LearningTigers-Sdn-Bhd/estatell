<?php

namespace Database\Factories;

use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Models\GuestRequest;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestRequest>
 */
class GuestRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'room_id' => fn (array $attributes): int => Room::factory()->create(['property_id' => $attributes['property_id']])->id,
            'type' => GuestRequestType::Housekeeping,
            'source' => GuestRequestSource::InPerson,
            'details' => 'Two extra towels',
            'status' => GuestRequestStatus::Open,
            'opened_at' => now(),
            'opened_business_date' => '2026-10-14',
        ];
    }

    public function inProgress(): static
    {
        return $this->state(['status' => GuestRequestStatus::InProgress]);
    }

    public function resolved(string $businessDate = '2026-10-14'): static
    {
        return $this->state(['status' => GuestRequestStatus::Resolved, 'resolved_at' => now(), 'resolved_business_date' => $businessDate]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => GuestRequestStatus::Cancelled, 'resolution_note' => 'Duplicate']);
    }
}

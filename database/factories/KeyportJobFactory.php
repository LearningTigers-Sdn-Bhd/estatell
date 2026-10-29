<?php

namespace Database\Factories;

use App\Enums\KeyportJobStatus;
use App\Enums\KeyportJobType;
use App\Models\KeyportDevice;
use App\Models\KeyportJob;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KeyportJob>
 */
class KeyportJobFactory extends Factory
{
    /**
     * A pending "make key card" job for room 101 that expires in ten minutes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => 'job_'.Str::ulid(),
            'property_id' => Property::factory(),
            'type' => KeyportJobType::Issue,
            'status' => KeyportJobStatus::Pending,
            'payload' => [
                'display' => ['room' => '101', 'guest' => 'A. Tan'],
                'lock_no' => '1.1.101',
                'check_in' => '2026-10-14 14:00:00',
                'check_out' => '2026-10-16 12:00:00',
                'flags' => 0,
                'expected_serial' => null,
            ],
            'expires_at' => now()->addMinutes(10),
            'idempotency_key' => fake()->unique()->uuid(),
        ];
    }

    public function claimedBy(KeyportDevice $device): static
    {
        return $this->state([
            'property_id' => $device->property_id,
            'device_id' => $device->id,
            'status' => KeyportJobStatus::Claimed,
            'claimed_at' => now(),
            'lease_until' => now()->addSeconds(config('keyport.lease_seconds')),
        ]);
    }

    public function ofType(KeyportJobType $type): static
    {
        return $this->state(['type' => $type]);
    }
}

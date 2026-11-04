<?php

namespace Database\Factories;

use App\Enums\BookingHoldStatus;
use App\Models\BookingClient;
use App\Models\BookingHold;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BookingHold>
 */
class BookingHoldFactory extends Factory
{
    /**
     * A Held hold with time left, for a stay of two nights. Add lines with `BookingHoldLine::factory()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'booking_client_id' => BookingClient::factory(),
            'token' => Str::random(64),
            'status' => BookingHoldStatus::Held,
            'arrival_date' => '2026-10-20',
            'departure_date' => '2026-10-22',
            'total_amount' => '200.00',
            'expires_at' => now()->addMinutes(BookingHold::MINUTES),
            'guest_name' => 'Aisyah Rahman',
            'guest_email' => 'aisyah@example.com',
            'guest_phone' => '+60123456789',
            'guest_nationality' => 'MY',
        ];
    }

    public function paid(): static
    {
        return $this->state(['status' => BookingHoldStatus::Paid]);
    }

    public function confirmed(): static
    {
        return $this->state(['status' => BookingHoldStatus::Confirmed]);
    }

    /**
     * Past the expiry, with the status not yet changed, as before the job runs.
     */
    public function pastExpiry(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function expired(): static
    {
        return $this->pastExpiry()->state(['status' => BookingHoldStatus::Expired]);
    }
}

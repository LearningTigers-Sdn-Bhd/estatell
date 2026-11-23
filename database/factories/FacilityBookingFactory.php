<?php

namespace Database\Factories;

use App\Enums\FacilityBookingStatus;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Folio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacilityBooking>
 */
class FacilityBookingFactory extends Factory
{
    /**
     * A Confirmed booking, 18:00–23:00 on the event's first day, charged to the event folio. Pass `event_id` and
     * `facility_id` of the same property; the event needs its folio (`EventFactory::withFolio()`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => fn (): int => Event::factory()->withFolio()->create()->id,
            'property_id' => fn (array $attributes): int => Event::query()->whereKey((int) $attributes['event_id'])->firstOrFail()->property_id,
            'facility_id' => fn (array $attributes): int => Facility::factory()->create(['property_id' => $attributes['property_id']])->id,
            'reservation_id' => null,
            'folio_id' => fn (array $attributes): int => Folio::query()->where('event_id', $attributes['event_id'])->firstOrFail()->id,
            'booking_date' => fn (array $attributes): string => Event::query()->whereKey((int) $attributes['event_id'])->firstOrFail()->starts_on->toDateString(),
            'starts_at' => '18:00',
            'ends_at' => '23:00',
            'quantity' => 1,
            'unit_amount' => '1000.00',
            'status' => FacilityBookingStatus::Confirmed,
        ];
    }

    public function tentative(): static
    {
        return $this->state(fn (array $attributes) => ['status' => FacilityBookingStatus::Tentative]);
    }
}

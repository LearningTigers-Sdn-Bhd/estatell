<?php

namespace Database\Factories;

use App\Enums\BillingMode;
use App\Enums\BookingSourceType;
use App\Enums\DocumentType;
use App\Enums\ReservationStatus;
use App\Models\Company;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\NightAudit\NextDocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * Define the model's default state. The reference comes from the property's document sequence, as in production.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'reference' => fn (array $attributes): string => app(NextDocumentNumber::class)(
                Property::query()->findOrFail((int) $attributes['property_id']),
                DocumentType::Reservation,
            )->reference,
            'status' => ReservationStatus::Confirmed,
            'booker_guest_id' => fn (array $attributes): int => Guest::factory()->create(['property_id' => $attributes['property_id']])->id,
            'billing_mode' => BillingMode::MasterPays,
            'booking_source_id' => fn (array $attributes): int => BookingSourceFactory::directFor((int) $attributes['property_id'])->id,
            'created_by' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
        ];
    }

    public function walkIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'booking_source_id' => fn (array $attributes): int => BookingSourceFactory::systemFor((int) $attributes['property_id'], BookingSourceType::WalkIn)->id,
        ]);
    }

    /**
     * An OTA booking: an OTA source of the property and an external reference.
     */
    public function ota(string $name = 'Booking.com'): static
    {
        return $this->state(fn (array $attributes) => [
            'booking_source_id' => fn (array $attributes): int => BookingSourceFactory::otaFor((int) $attributes['property_id'], $name)->id,
            'external_reference' => 'OTA-'.fake()->unique()->numerify('######'),
        ]);
    }

    /**
     * A Company / Travel agent booking: the system source and a company of the property.
     */
    public function company(?Company $company = null): static
    {
        return $this->state(fn (array $attributes) => [
            'booking_source_id' => fn (array $attributes): int => BookingSourceFactory::systemFor((int) $attributes['property_id'], BookingSourceType::Company)->id,
            'company_id' => fn (array $attributes): int => $company->id ?? Company::factory()->create(['property_id' => $attributes['property_id']])->id,
        ]);
    }

    public function group(string $name = 'Wedding party'): static
    {
        return $this->state(fn (array $attributes) => ['group_name' => $name]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ReservationStatus::Cancelled]);
    }
}

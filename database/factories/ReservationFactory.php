<?php

namespace Database\Factories;

use App\Enums\BillingMode;
use App\Enums\DocumentType;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
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
            'source' => ReservationSource::Direct,
            'created_by' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
        ];
    }

    public function walkIn(): static
    {
        return $this->state(fn (array $attributes) => ['source' => ReservationSource::WalkIn]);
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

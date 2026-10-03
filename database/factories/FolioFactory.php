<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\NightAudit\NextDocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Folio>
 */
class FolioFactory extends Factory
{
    /**
     * Define the model's default state: an open master folio with no payer. The number comes from the property's
     * document sequence, as in production.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'reservation_id' => fn (array $attributes): int => Reservation::factory()->create(['property_id' => $attributes['property_id']])->id,
            'reservation_unit_id' => null,
            'number' => fn (array $attributes): string => app(NextDocumentNumber::class)(
                Property::query()->findOrFail($attributes['property_id']),
                DocumentType::Folio,
            )->reference,
            'type' => FolioType::Master,
            'status' => FolioStatus::Open,
        ];
    }

    public function forUnit(?ReservationUnit $unit = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => FolioType::Unit,
            'reservation_unit_id' => $unit?->id ?? fn (array $attributes): int => ReservationUnit::factory()->create([
                'reservation_id' => $attributes['reservation_id'],
            ])->id,
        ]);
    }

    public function paidBy(Guest $guest): static
    {
        return $this->state(fn (array $attributes) => ['payer_guest_id' => $guest->id]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FolioStatus::Closed,
            'closed_at' => now(),
            'closed_by' => User::factory()->create(['property_id' => $attributes['property_id']])->id,
        ]);
    }
}

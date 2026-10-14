<?php

namespace Database\Factories;

use App\Enums\RoutingRuleSource;
use App\Enums\TransactionCategory;
use App\Models\Folio;
use App\Models\FolioRoutingRule;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FolioRoutingRule>
 */
class FolioRoutingRuleFactory extends Factory
{
    /**
     * Define the model's default state: a Manual rule that matches everything and sends it to a Master folio of the
     * same reservation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'reservation_unit_id' => null,
            'category' => null,
            'transaction_code_id' => null,
            'target_folio_id' => fn (array $attributes): int => Folio::factory()->create([
                'reservation_id' => $attributes['reservation_id'],
                'property_id' => Reservation::query()->findOrFail((int) $attributes['reservation_id'])->property_id,
            ])->id,
            'source' => RoutingRuleSource::Manual,
        ];
    }

    public function forUnit(ReservationUnit $unit): static
    {
        return $this->state(fn (array $attributes) => ['reservation_unit_id' => $unit->id]);
    }

    public function forCategory(TransactionCategory $category): static
    {
        return $this->state(fn (array $attributes) => ['category' => $category]);
    }

    public function forCode(TransactionCode $code): static
    {
        return $this->state(fn (array $attributes) => ['transaction_code_id' => $code->id]);
    }

    public function toFolio(Folio $folio): static
    {
        return $this->state(fn (array $attributes) => ['target_folio_id' => $folio->id]);
    }

    public function fromBillingMode(): static
    {
        return $this->state(fn (array $attributes) => ['source' => RoutingRuleSource::BillingMode]);
    }
}

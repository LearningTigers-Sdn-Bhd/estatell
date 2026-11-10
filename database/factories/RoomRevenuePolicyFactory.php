<?php

namespace Database\Factories;

use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomRevenuePolicyStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomRevenuePolicy>
 */
class RoomRevenuePolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'code' => SystemTransactionCode::CancellationRevenue,
            'charge_mode' => PolicyChargeMode::Free,
            'amount' => null,
            'percent' => null,
            'percent_basis' => null,
            'applies_to' => PolicyScope::FirstNight,
            'own_taxes' => false,
            'text_en' => 'No charge for cancellation.',
            'text_ms' => 'Tiada caj untuk pembatalan.',
        ];
    }

    /**
     * A paid cancellation keeps its charge in steps: the charge from `percentage()` or `fixed()` becomes a step at 0 days.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (RoomRevenuePolicy $policy): void {
            if (! $this->isPaidCancellation($policy) || $policy->relationLoaded('steps')) {
                return;
            }

            $policy->setRelation('steps', collect([$this->step(0, $policy->amount, $policy->percent)]));
            $policy->amount = null;
            $policy->percent = null;
        })->afterCreating(function (RoomRevenuePolicy $policy): void {
            if (! $policy->relationLoaded('steps')) {
                return;
            }

            foreach ($policy->steps as $step) {
                $step->forceFill(['property_id' => $policy->property_id, 'room_revenue_policy_id' => $policy->id])->save();
            }
        });
    }

    /**
     * A free cancellation window: free from this many days before arrival, as a 0 step above the charge.
     */
    public function freeUntil(int $days): static
    {
        return $this->afterMaking(function (RoomRevenuePolicy $policy) use ($days): void {
            $isFixed = $policy->charge_mode === PolicyChargeMode::FixedAmount;
            $policy->setRelation('steps', $policy->steps->prepend($this->step($days, $isFixed ? '0.00' : null, $isFixed ? null : '0.00')));
        });
    }

    /**
     * Cancellation steps as days before arrival => charge, the percent or the amount of the charge mode.
     *
     * @param  array<int, string>  $steps
     */
    public function steps(array $steps): static
    {
        return $this->afterMaking(function (RoomRevenuePolicy $policy) use ($steps): void {
            $isFixed = $policy->charge_mode === PolicyChargeMode::FixedAmount;
            krsort($steps);
            $policy->setRelation('steps', collect(array_map(
                fn (int $days, string $charge): RoomRevenuePolicyStep => $this->step($days, $isFixed ? $charge : null, $isFixed ? null : $charge),
                array_keys($steps),
                array_values($steps),
            )));
            $policy->amount = null;
            $policy->percent = null;
        });
    }

    private function isPaidCancellation(RoomRevenuePolicy $policy): bool
    {
        return $policy->code === SystemTransactionCode::CancellationRevenue && $policy->charge_mode !== PolicyChargeMode::Free;
    }

    private function step(int $days, ?string $amount, ?string $percent): RoomRevenuePolicyStep
    {
        return new RoomRevenuePolicyStep(['days_before' => $days, 'amount' => $amount, 'percent' => $percent]);
    }

    public function forCode(SystemTransactionCode $code, PolicyScope $scope): static
    {
        return $this->state(fn (array $attributes) => ['code' => $code, 'applies_to' => $scope]);
    }

    public function percentage(string $percent = '100.00', PolicyRateBasis $basis = PolicyRateBasis::BookedRate): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_mode' => PolicyChargeMode::Percentage,
            'percent' => $percent,
            'percent_basis' => $basis,
        ]);
    }

    public function fixed(string $amount = '50.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_mode' => PolicyChargeMode::FixedAmount,
            'amount' => $amount,
        ]);
    }
}

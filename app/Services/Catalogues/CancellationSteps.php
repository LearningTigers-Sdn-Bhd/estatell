<?php

namespace App\Services\Catalogues;

use App\Enums\PolicyChargeMode;
use App\Enums\SystemTransactionCode;
use App\Models\ReservationCancellationTermStep;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomRevenuePolicyStep;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Reads the charge steps of a paid cancellation: "N or more days before arrival → charge". The step with the most days
 * that the cancellation reaches applies; closer to arrival than the last step, the last step applies. A 0 step is free.
 * See docs/06-catalogues/06-room-revenue.md#policies.
 */
class CancellationSteps
{
    public function usesSteps(RoomRevenuePolicy $policy): bool
    {
        return $policy->code === SystemTransactionCode::CancellationRevenue && $policy->charge_mode !== PolicyChargeMode::Free;
    }

    /**
     * The steps of a policy, most days first. A saved policy loads them once. Other policies have none.
     *
     * @return Collection<int, RoomRevenuePolicyStep>
     */
    public function of(RoomRevenuePolicy $policy): Collection
    {
        if (! $this->usesSteps($policy)) {
            return collect();
        }

        if ($policy->exists) {
            $policy->loadMissing('steps');
        }

        return $policy->relationLoaded('steps') ? $policy->steps->sortByDesc('days_before')->values() : collect();
    }

    /**
     * @template TStep of RoomRevenuePolicyStep|ReservationCancellationTermStep
     *
     * @param  Collection<int, TStep>  $steps  Most days first.
     * @return TStep|null
     */
    public function stepFor(Collection $steps, int $daysBeforeArrival): RoomRevenuePolicyStep|ReservationCancellationTermStep|null
    {
        return $steps->first(fn (RoomRevenuePolicyStep|ReservationCancellationTermStep $step): bool => $daysBeforeArrival >= $step->days_before) ?? $steps->last();
    }

    public function isFree(RoomRevenuePolicyStep|ReservationCancellationTermStep $step): bool
    {
        return BigDecimal::of($step->amount ?? $step->percent ?? '0')->isZero();
    }

    /**
     * The policy with the step's charge in `amount` or `percent`, so the charge is worked out as for any other policy.
     */
    public function chargedAt(RoomRevenuePolicy $policy, RoomRevenuePolicyStep $step): RoomRevenuePolicy
    {
        $charged = clone $policy;
        $charged->amount = $step->amount;
        $charged->percent = $step->percent;

        return $charged;
    }

    /**
     * The free window: free from this many days before arrival. It is the top run of 0 steps, or null when the top step
     * charges.
     *
     * @param  Collection<int, RoomRevenuePolicyStep>|Collection<int, ReservationCancellationTermStep>  $steps  Most days first.
     */
    public function freeUntilDays(Collection $steps): ?int
    {
        $freeUntil = null;

        foreach ($steps as $step) {
            if (! $this->isFree($step)) {
                break;
            }

            $freeUntil = $step->days_before;
        }

        return $freeUntil;
    }

    /**
     * The first step that charges, most days first.
     *
     * @template TStep of RoomRevenuePolicyStep|ReservationCancellationTermStep
     *
     * @param  Collection<int, TStep>  $steps  Most days first.
     * @return TStep|null
     */
    public function firstCharging(Collection $steps): RoomRevenuePolicyStep|ReservationCancellationTermStep|null
    {
        return $steps->first(fn (RoomRevenuePolicyStep|ReservationCancellationTermStep $step): bool => ! $this->isFree($step));
    }
}

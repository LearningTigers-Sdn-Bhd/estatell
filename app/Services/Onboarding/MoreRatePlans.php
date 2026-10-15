<?php

namespace App\Services\Onboarding;

use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Finds the More rates of the wizard: every active nightly plan sold per room or per guest that is not a Basic Rate
 * plan. A rate that has its own prices for room types on both bases is two plans, "{name} / Room" and "{name} / Guest",
 * and counts as one row. It only reads.
 */
class MoreRatePlans
{
    public const ROOM_SUFFIX = ' / Room';

    public const GUEST_SUFFIX = ' / Guest';

    /**
     * The property's More rate plans in list order.
     *
     * @return EloquentCollection<int, RatePlan>
     */
    public function forProperty(Property $property): Collection
    {
        return $property->ratePlans()
            ->active()
            ->whereIn('pricing_basis', [PricingBasis::PerRoom, PricingBasis::PerGuest])
            ->whereNotIn('name', [BasicRatePlans::NAME, BasicRatePlans::ROOM_NAME, BasicRatePlans::GUEST_NAME])
            ->ordered()
            ->with('roomTypes')
            ->get();
    }

    /**
     * The plans grouped into rows. A follower is a row of its own. Two plans with their own prices that share a base name
     * and end in the Room and Guest suffixes are one row.
     *
     * @param  Collection<int, RatePlan>  $plans
     * @return list<array{name: string, plans: list<RatePlan>}>
     */
    public function groups(Collection $plans): array
    {
        $groups = [];

        foreach ($plans as $plan) {
            $base = $this->baseName($plan, $plans);
            $key = $plan->parent_rate_plan_id === null ? 'own:'.mb_strtolower($base) : 'plan:'.$plan->id;

            $groups[$key] ??= ['name' => $base, 'plans' => []];
            $groups[$key]['plans'][] = $plan;
        }

        return array_values($groups);
    }

    /**
     * The plan name for a basis, given every basis the rate uses: the plain name for one basis, the suffixed names for both.
     *
     * @param  list<PricingBasis>  $bases
     */
    public function nameFor(string $name, PricingBasis $basis, array $bases): string
    {
        if (count($bases) < 2) {
            return $name;
        }

        return $name.($basis === PricingBasis::PerGuest ? self::GUEST_SUFFIX : self::ROOM_SUFFIX);
    }

    /**
     * @param  Collection<int, RatePlan>  $plans
     */
    private function baseName(RatePlan $plan, Collection $plans): string
    {
        if ($plan->parent_rate_plan_id !== null) {
            return $plan->name;
        }

        foreach ([self::ROOM_SUFFIX => PricingBasis::PerRoom, self::GUEST_SUFFIX => PricingBasis::PerGuest] as $suffix => $basis) {
            $base = str_ends_with($plan->name, $suffix) ? substr($plan->name, 0, -strlen($suffix)) : null;
            $otherSuffix = $suffix === self::ROOM_SUFFIX ? self::GUEST_SUFFIX : self::ROOM_SUFFIX;

            if ($base !== null && $base !== '' && $plan->pricing_basis === $basis && $plans->contains(fn (RatePlan $other): bool => $other->parent_rate_plan_id === null && $other->name === $base.$otherSuffix)) {
                return $base;
            }
        }

        return $plan->name;
    }
}

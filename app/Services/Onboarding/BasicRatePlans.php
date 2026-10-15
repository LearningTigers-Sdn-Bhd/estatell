<?php

namespace App\Services\Onboarding;

use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
use Illuminate\Support\Collection;

/**
 * Finds the Basic Rate plans of the wizard. SeedRatePlans creates "Basic Rate" with no flag, so a plan counts as a Basic
 * Rate plan by its name ("Basic Rate", "Basic Rate / Room" or "Basic Rate / Guest"), as long as it is active, follows no
 * other plan and prices nights per room or per guest. More rates have other names. It only reads.
 */
class BasicRatePlans
{
    public const NAME = 'Basic Rate';

    public const ROOM_NAME = 'Basic Rate / Room';

    public const GUEST_NAME = 'Basic Rate / Guest';

    /**
     * The property's Basic Rate plans in list order.
     *
     * @return Collection<int, RatePlan>
     */
    public function forProperty(Property $property): Collection
    {
        return $property->ratePlans()
            ->active()
            ->whereNull('parent_rate_plan_id')
            ->whereIn('pricing_basis', [PricingBasis::PerRoom, PricingBasis::PerGuest])
            ->whereIn('name', [self::NAME, self::ROOM_NAME, self::GUEST_NAME])
            ->ordered()
            ->get();
    }

    /**
     * The plan names for the bases the rows use: one plain name when a single basis is used, the Room and Guest names when both are.
     *
     * @param  list<PricingBasis>  $bases
     */
    public function nameFor(PricingBasis $basis, array $bases): string
    {
        if (count($bases) < 2) {
            return self::NAME;
        }

        return $basis === PricingBasis::PerGuest ? self::GUEST_NAME : self::ROOM_NAME;
    }
}

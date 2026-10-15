<?php

namespace App\Services\Onboarding;

use App\Data\Onboarding\OnboardingMoreRateData;
use App\Enums\RateDerivation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;

/**
 * Reads the More rates table back from the saved plans: a follower is a row with its change, and a rate with its own prices
 * is a row with a price line for each room type (its Room and Guest plans are one row). It only reads.
 */
class MoreRateRows
{
    public function __construct(private MoreRatePlans $moreRatePlans, private BasicRateRows $basicRateRows) {}

    /**
     * The rows, and whether every own price has one amount on all 7 nights.
     *
     * @return array{rows: list<OnboardingMoreRateData>, sameEveryNight: bool}
     */
    public function forProperty(Property $property): array
    {
        $plans = $this->moreRatePlans->forProperty($property);
        $same = true;
        $rows = [];

        foreach ($this->moreRatePlans->groups($plans) as $group) {
            $first = $group['plans'][0];

            if ($first->parent_rate_plan_id !== null) {
                $rows[] = $this->follower($first);

                continue;
            }

            $roomTypes = RoomType::query()
                ->whereKey(collect($group['plans'])->flatMap(fn (RatePlan $plan): array => $plan->roomTypes->pluck('id')->all())->unique()->all())
                ->active()
                ->ordered()
                ->with('ratePlans')
                ->get();
            $prices = $this->basicRateRows->read($property, collect($group['plans']), $roomTypes);
            $same = $same && $prices['sameEveryNight'];

            $rows[] = new OnboardingMoreRateData(
                key: 'plan-'.$first->id,
                planIds: array_map(fn (RatePlan $plan): int => $plan->id, $group['plans']),
                name: $group['name'],
                mode: 'own',
                parentBasis: null,
                direction: 'up',
                unit: 'amount',
                amount: null,
                roomTypeIds: array_values(array_map(intval(...), $roomTypes->pluck('id')->all())),
                prices: array_values($prices['rows']->all()),
            );
        }

        return ['rows' => $rows, 'sameEveryNight' => $same];
    }

    private function follower(RatePlan $plan): OnboardingMoreRateData
    {
        $value = (float) $plan->derivation_value;

        return new OnboardingMoreRateData(
            key: 'plan-'.$plan->id,
            planIds: [$plan->id],
            name: $plan->name,
            mode: 'follows',
            parentBasis: $plan->pricing_basis,
            direction: $value < 0 ? 'down' : 'up',
            unit: $plan->derivation === RateDerivation::Percent ? 'percent' : 'amount',
            amount: number_format(abs($value), 2, '.', ''),
            roomTypeIds: array_values(array_map(intval(...), $plan->roomTypes->whereNull('archived_at')->sortBy([['position', 'asc'], ['id', 'asc']])->pluck('id')->all())),
            prices: [],
        );
    }
}

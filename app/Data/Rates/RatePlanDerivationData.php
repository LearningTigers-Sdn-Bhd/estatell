<?php

namespace App\Data\Rates;

use App\Enums\RateDerivation;
use App\Models\RatePlan;
use App\Services\Rates\DerivedRatePlans;
use Spatie\LaravelData\Data;

/** The "Follow another plan" state of the Pricing tab: the saved derivation, the eligible parents and why following is off. */
class RatePlanDerivationData extends Data
{
    /**
     * @param  list<RatePlanParentOptionData>  $parents
     */
    public function __construct(
        public ?int $parentRatePlanId,
        public ?RateDerivation $derivation,
        public ?string $direction,
        public ?string $value,
        public array $parents,
        public ?string $cannotFollowReason,
    ) {}

    public static function forPlan(RatePlan $ratePlan, DerivedRatePlans $derivedRatePlans): self
    {
        $value = $ratePlan->derivation_value;

        return new self(
            parentRatePlanId: $ratePlan->parent_rate_plan_id,
            derivation: $ratePlan->derivation,
            direction: $value === null ? null : ((float) $value < 0 ? 'down' : 'up'),
            value: $value === null ? null : ltrim($value, '-'),
            parents: $derivedRatePlans->eligibleParents($ratePlan)
                ->map(fn (RatePlan $parent): RatePlanParentOptionData => new RatePlanParentOptionData($parent->id, $parent->name))
                ->values()
                ->all(),
            cannotFollowReason: $derivedRatePlans->cannotFollowReason($ratePlan),
        );
    }
}

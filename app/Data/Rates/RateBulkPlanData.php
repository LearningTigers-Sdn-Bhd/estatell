<?php

namespace App\Data\Rates;

use App\Enums\PricingBasis;
use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/** One plan in the bulk edit plan picker. Only a plan with its own prices can have its prices changed in bulk. */
class RateBulkPlanData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public PricingBasis $pricingBasis,
        public bool $derived,
        public ?string $followsName,
        public bool $hasOwnPrices,
    ) {}

    /**
     * Load the plan's `parent` first when it is derived.
     */
    public static function fromModel(RatePlan $ratePlan): self
    {
        $derived = $ratePlan->parent_rate_plan_id !== null;

        return new self(
            id: $ratePlan->id,
            name: $ratePlan->name,
            pricingBasis: $ratePlan->pricing_basis,
            derived: $derived,
            followsName: $ratePlan->parent?->name,
            hasOwnPrices: ! $derived && $ratePlan->pricing_basis !== PricingBasis::Package,
        );
    }
}

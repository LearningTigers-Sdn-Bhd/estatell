<?php

namespace App\Data\Rates;

use App\Enums\PricingBasis;
use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/** One row of the rate plans list, and the values of the plan editor. */
class RatePlanData extends Data
{
    /**
     * @param  list<int>  $roomTypeIds  The active room types the plan sells.
     * @param  list<string>  $roomTypeNames  The names of those room types, in display order.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public PricingBasis $pricingBasis,
        public string $family,
        public string $pricingLabel,
        public string $extraAdultAmount,
        public string $extraChildAmount,
        public string $singleSupplement,
        public bool $archived,
        public bool $checkChildPrices,
        public array $roomTypeIds,
        public array $roomTypeNames,
    ) {}

    /**
     * Load the plan's `roomTypes` first. Archived room types are left out.
     */
    public static function fromModel(RatePlan $ratePlan): self
    {
        $roomTypes = $ratePlan->roomTypes->whereNull('archived_at')->sortBy(['position', 'id'])->values();

        return new self(
            id: $ratePlan->id,
            name: $ratePlan->name,
            description: $ratePlan->description,
            pricingBasis: $ratePlan->pricing_basis,
            family: $ratePlan->pricing_basis->family(),
            pricingLabel: $ratePlan->pricing_basis->label(),
            extraAdultAmount: $ratePlan->extra_adult_amount,
            extraChildAmount: $ratePlan->extra_child_amount,
            singleSupplement: $ratePlan->single_supplement,
            archived: $ratePlan->archived_at !== null,
            checkChildPrices: $ratePlan->needs_child_price_review,
            roomTypeIds: $roomTypes->pluck('id')->all(),
            roomTypeNames: $roomTypes->pluck('name')->all(),
        );
    }
}

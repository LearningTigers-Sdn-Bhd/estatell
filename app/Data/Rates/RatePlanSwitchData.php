<?php

namespace App\Data\Rates;

use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/**
 * One rate plan in the breadcrumb switch menu of the plan editor. The pricing fields tell the browser which tabs the
 * plan has, so the menu keeps the open tab only when the other plan has it too.
 */
class RatePlanSwitchData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public PricingBasis $pricingBasis,
        public bool $usesChildPrices,
    ) {}

    public static function fromModel(RatePlan $ratePlan): self
    {
        return new self(
            id: $ratePlan->id,
            name: $ratePlan->name,
            pricingBasis: $ratePlan->pricing_basis,
            usesChildPrices: $ratePlan->pricing_basis->usesChildPrices($ratePlan->package_unit),
        );
    }

    /**
     * The property's active rate plans in display order, plus the open one when it is archived.
     *
     * @return list<self>
     */
    public static function forProperty(Property $property, RatePlan $current): array
    {
        return $property->ratePlans()
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhere('id', $current->id))
            ->ordered()
            ->get()
            ->map(fn (RatePlan $ratePlan): self => self::fromModel($ratePlan))
            ->values()
            ->all();
    }
}

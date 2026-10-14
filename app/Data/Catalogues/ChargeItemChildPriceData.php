<?php

namespace App\Data\Catalogues;

use App\Enums\AgeBandPricing;
use App\Models\ChargeItem;
use App\Models\ChargeItemChildPrice;
use App\Models\PropertyAgeGroup;
use Spatie\LaravelData\Data;

/** One row of the charge item edit page's Children section: an age group and how the item charges it. */
class ChargeItemChildPriceData extends Data
{
    public function __construct(
        public int $ageGroupId,
        public string $label,
        public int $minAge,
        public int $maxAge,
        public AgeBandPricing $pricing,
        public ?string $value,
    ) {}

    /**
     * A group with no saved row shows the default a new row would get: Percent 100, or Free for an item priced when
     * charging.
     */
    public static function forAgeGroup(ChargeItem $chargeItem, PropertyAgeGroup $ageGroup, ?ChargeItemChildPrice $price): self
    {
        $priced = $chargeItem->default_amount !== null;

        return new self(
            ageGroupId: $ageGroup->id,
            label: $ageGroup->label,
            minAge: $ageGroup->min_age,
            maxAge: $ageGroup->max_age,
            pricing: $price->pricing ?? ($priced ? AgeBandPricing::Percent : AgeBandPricing::Free),
            value: $price !== null ? $price->value : ($priced ? '100.00' : null),
        );
    }
}

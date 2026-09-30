<?php

namespace App\Data\Rates;

use App\Enums\StayDiscountType;
use App\Models\RatePlanStayDiscount;
use Spatie\LaravelData\Data;

/** One row of the plan editor's Discounts tab: a long-stay discount rule. */
class RatePlanStayDiscountData extends Data
{
    public function __construct(
        public int $minNights,
        public StayDiscountType $discountType,
        public string $value,
        public int $fromNight,
    ) {}

    public static function fromModel(RatePlanStayDiscount $discount): self
    {
        return new self(
            minNights: $discount->min_nights,
            discountType: $discount->discount_type,
            value: $discount->value,
            fromNight: $discount->from_night,
        );
    }
}

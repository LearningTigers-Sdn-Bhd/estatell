<?php

namespace App\Services\Rates;

use App\Enums\StayDiscountType;
use App\Models\RatePlanStayDiscount;
use App\Services\Rates\Pricing\NightPrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Takes the long-stay discount off a priced stay. The longest rule the stay qualifies for wins and applies from its
 * `from_night`. A percent is of the night total. An amount is per unit (guest, bed or room). A night never goes below zero.
 */
class StayDiscount
{
    /**
     * @param  Collection<int, RatePlanStayDiscount>  $rules
     */
    public function ruleFor(Collection $rules, int $nights): ?RatePlanStayDiscount
    {
        return $rules
            ->filter(fn (RatePlanStayDiscount $rule): bool => $rule->min_nights <= $nights)
            ->sortByDesc('min_nights')
            ->first();
    }

    /**
     * @param  Collection<int, RatePlanStayDiscount>  $rules
     * @param  list<NightPrice>  $nights
     * @param  int  $units  How many units an amount is taken off, from `StayPricing::discountUnits()`
     * @return list<NightPrice>
     */
    public function apply(Collection $rules, array $nights, int $units): array
    {
        $rule = $this->ruleFor($rules, count($nights));

        if ($rule === null) {
            return $nights;
        }

        $discounted = [];

        foreach ($nights as $index => $night) {
            $discounted[] = $index + 1 >= $rule->from_night
                ? $night->withDiscount($this->discountFor($rule, $night, $units))
                : $night;
        }

        return $discounted;
    }

    private function discountFor(RatePlanStayDiscount $rule, NightPrice $night, int $units): Money
    {
        $total = $night->total();
        $discount = $rule->discount_type === StayDiscountType::Percent
            ? $total->multipliedBy(BigDecimal::of($rule->value)->dividedBy(100, 8), RoundingMode::HalfUp)
            : Money::of($rule->value, $total->getCurrency())->multipliedBy($units);

        return $discount->isGreaterThan($total) ? $total : $discount;
    }
}

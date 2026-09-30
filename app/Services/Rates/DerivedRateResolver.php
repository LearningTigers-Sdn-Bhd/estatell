<?php

namespace App\Services\Rates;

use App\Enums\RateDerivation;
use App\Models\RatePlan;
use App\Services\Rates\Pricing\NightPrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Adjusts a night priced from a parent plan by the derived plan's percent or amount. It reads only.
 * A night never goes below zero.
 */
class DerivedRateResolver
{
    /**
     * A percent scales the base, the extras and the supplement. An amount is added per unit (guest, bed or room)
     * to the base. A drop that empties the base takes the rest from the extras, then the supplement.
     *
     * @param  int  $units  From `StayPricing::discountUnits()`, used by an amount
     */
    public function resolve(NightPrice $night, RatePlan $derived, int $units): NightPrice
    {
        $value = BigDecimal::of($derived->derivation_value ?? 0);

        return match ($derived->derivation) {
            RateDerivation::Percent => $this->percent($night, $value),
            RateDerivation::Amount => $this->amount($night, Money::of($value, $night->base->getCurrency(), roundingMode: RoundingMode::HalfUp)->multipliedBy($units)),
            null => $night,
        };
    }

    private function percent(NightPrice $night, BigDecimal $value): NightPrice
    {
        $factor = BigDecimal::one()->plus($value->dividedBy(100, 8));
        $scale = fn (Money $money): Money => $this->floor($money->multipliedBy($factor, RoundingMode::HalfUp));

        return NightPrice::of($night->date, $scale($night->base), $scale($night->extra), $scale($night->supplement));
    }

    private function amount(NightPrice $night, Money $change): NightPrice
    {
        $zero = Money::zero($change->getCurrency());
        $base = $night->base->plus($change);

        if (! $base->isNegative()) {
            return NightPrice::of($night->date, $base, $night->extra, $night->supplement);
        }

        $extra = $night->extra->plus($base);

        if (! $extra->isNegative()) {
            return NightPrice::of($night->date, $zero, $extra, $night->supplement);
        }

        return NightPrice::of($night->date, $zero, $zero, $this->floor($night->supplement->plus($extra)));
    }

    private function floor(Money $money): Money
    {
        return $money->isNegative() ? Money::zero($money->getCurrency()) : $money;
    }
}

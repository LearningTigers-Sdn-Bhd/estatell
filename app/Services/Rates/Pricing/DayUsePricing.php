<?php

namespace App\Services\Rates\Pricing;

use App\Enums\PricingBasis;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Rates\RateQuote;
use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * Prices one day use block: the `rate_hour_prices` row for the room type, the plan, the date and the number of hours.
 * It does not implement `StayPricing`, because that interface prices nights. `RateQuoteService::quoteDayUse()` calls it.
 * Stop sell on the date's `rates` row refuses with `restricted`, unless the caller has accepted the override. A block
 * entered after it started skips the plan's min and max hours (`$ignoreHoursLimit`).
 * Min stay, max stay and the closed to arrival and departure flags do not apply. See docs/12-reservations/10-day-use.md.
 */
class DayUsePricing
{
    /**
     * @throws QuoteRefused when the block cannot be priced
     */
    public function price(RatePlan $plan, RoomType $roomType, CarbonInterface $date, int $hours, string $currency, bool $ignoreStopSell = false, bool $ignoreHoursLimit = false): Money
    {
        if ($plan->pricing_basis !== PricingBasis::DayUse) {
            throw new QuoteRefused(RateQuote::REFUSAL_PLAN_NOT_DAY_USE);
        }

        if ($plan->archived_at !== null) {
            throw new QuoteRefused(RateQuote::REFUSAL_PLAN_ARCHIVED);
        }

        if (! $plan->roomTypes()->whereKey($roomType->id)->exists()) {
            throw new QuoteRefused(RateQuote::REFUSAL_ROOM_TYPE_NOT_SOLD);
        }

        if (! $ignoreHoursLimit && ($hours < ($plan->day_use_min_hours ?? 1) || $hours > ($plan->day_use_max_hours ?? 24))) {
            throw new QuoteRefused(RateQuote::REFUSAL_HOURS_NOT_SOLD);
        }

        $rate = Rate::query()
            ->where('rate_plan_id', $plan->id)
            ->where('room_type_id', $roomType->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($rate !== null && $rate->stop_sell && ! $ignoreStopSell) {
            throw new QuoteRefused(RateQuote::REFUSAL_RESTRICTED, $date);
        }

        $price = $rate?->hourPrices()->where('hours', $hours)->first();

        if ($price === null) {
            throw new QuoteRefused(RateQuote::REFUSAL_NO_HOUR_PRICE, $date);
        }

        return Money::of($price->amount, $currency);
    }
}

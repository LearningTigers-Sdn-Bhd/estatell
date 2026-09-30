<?php

namespace App\Services\Rates\Pricing;

use App\Models\Rate;
use App\Services\Rates\RateQuote;
use Carbon\CarbonImmutable;

/**
 * A strategy that prices each night from that night's `rates` row. A night with no row or no amount is refused.
 */
abstract class NightlyPricing implements StayPricing
{
    public function priceStay(StayContext $stay, CarbonImmutable $arrival, CarbonImmutable $departure): array
    {
        $rates = Rate::query()
            ->with('occupancyPrices')
            ->where('rate_plan_id', $stay->ratePlan->id)
            ->where('room_type_id', $stay->roomType->id)
            ->where('date', '>=', $arrival->toDateString())
            ->where('date', '<', $departure->toDateString())
            ->get()
            ->keyBy(fn (Rate $rate): string => $rate->date->toDateString());

        $nights = [];

        for ($date = $arrival; $date->lt($departure); $date = $date->addDay()) {
            $rate = $rates->get($date->toDateString());

            if ($rate === null || $rate->amount === null) {
                throw new QuoteRefused(RateQuote::REFUSAL_RATE_MISSING, $date);
            }

            $nights[] = $this->priceNight($stay, $rate);
        }

        return $nights;
    }

    /**
     * @throws QuoteRefused when the night cannot be priced
     */
    abstract protected function priceNight(StayContext $stay, Rate $rate): NightPrice;
}

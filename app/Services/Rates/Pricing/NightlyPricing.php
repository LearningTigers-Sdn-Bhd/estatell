<?php

namespace App\Services\Rates\Pricing;

use App\Models\Rate;
use App\Services\Packages\InclusionAllocator;
use App\Services\Rates\RateQuote;
use Carbon\CarbonImmutable;

/**
 * A strategy that prices each night from that night's `rates` row. A night with no row or no amount is refused.
 */
abstract class NightlyPricing implements StayPricing
{
    public function __construct(private InclusionAllocator $inclusionAllocator) {}

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
     * Each night is priced on its own date, so the nights stay as they are. A night before the long-stay discount that
     * is less than the inclusions it posts is refused, so the night audit can post it.
     */
    public function fitInclusions(array $nights, iterable $inclusions, int $guests): array
    {
        foreach ($nights as $index => $night) {
            $included = $this->inclusionAllocator->total($night->base->getCurrency(), $inclusions, $guests, $index === 0);

            if ($night->total()->isLessThan($included)) {
                throw new QuoteRefused(RateQuote::REFUSAL_RATE_BELOW_INCLUSIONS, $night->date);
            }
        }

        return $nights;
    }

    /**
     * @throws QuoteRefused when the night cannot be priced
     */
    abstract protected function priceNight(StayContext $stay, Rate $rate): NightPrice;
}

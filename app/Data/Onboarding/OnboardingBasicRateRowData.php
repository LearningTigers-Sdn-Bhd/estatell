<?php

namespace App\Data\Onboarding;

use App\Enums\PricingBasis;
use Spatie\LaravelData\Data;

/**
 * One room type of the onboarding Basic rate table: how it is priced and the weekday and weekend price read back from
 * its saved rates. `hasSavedPrices` lets the page ask before a switch of Price by clears them.
 */
class OnboardingBasicRateRowData extends Data
{
    public function __construct(
        public int $roomTypeId,
        public string $name,
        public PricingBasis $priceBy,
        public ?string $weekday,
        public ?string $weekend,
        public bool $hasSavedPrices,
        public int $baseOccupancy,
    ) {}
}

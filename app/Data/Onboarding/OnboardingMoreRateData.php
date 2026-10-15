<?php

namespace App\Data\Onboarding;

use App\Enums\PricingBasis;
use Spatie\LaravelData\Data;

/**
 * One row of the More rates table. A follower (`mode` follows) takes the price of the Basic Rate plan of `parentBasis`,
 * moved `direction` (up or down) by `amount` in `unit` (amount or percent), for `roomTypeIds`. A rate with its own prices
 * (`mode` own) holds a Basic rate style row for each room type in `prices`. `planIds` are the saved plans behind the row.
 */
class OnboardingMoreRateData extends Data
{
    /**
     * @param  list<int>  $planIds
     * @param  list<int>  $roomTypeIds
     * @param  list<OnboardingBasicRateRowData>  $prices
     */
    public function __construct(
        public string $key,
        public array $planIds,
        public string $name,
        public string $mode,
        public ?PricingBasis $parentBasis,
        public string $direction,
        public string $unit,
        public ?string $amount,
        public array $roomTypeIds,
        public array $prices,
    ) {}
}

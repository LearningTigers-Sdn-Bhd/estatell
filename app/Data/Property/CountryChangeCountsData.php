<?php

namespace App\Data\Property;

use Spatie\LaravelData\Data;

/**
 * What a country change after setup started removes or clears. The server counts them for the Change country dialog.
 * `prices` counts every priced row: rate amounts, occupancy and hour prices, package prices, child prices, plan extra
 * amounts and amount-off stay discounts.
 */
class CountryChangeCountsData extends Data
{
    public function __construct(
        public int $taxes,
        public int $paymentMethods,
        public int $chargeItems,
        public int $discounts,
        public int $prices,
        public int $inclusions,
        public int $termsDocuments,
        public int $skippedSteps,
    ) {}
}

<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * One transaction code of the Revenue chart with its amount over the whole range. The last entry may be "Other codes"
 * (`code` empty), the sum of the codes past `RevenueChart::TOP_CODES`.
 */
class RevenueChartCodeData extends Data
{
    public function __construct(
        public string $code,
        public string $description,
        public string $amount,
    ) {}
}

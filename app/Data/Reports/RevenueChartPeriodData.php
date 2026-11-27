<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * One day (`Y-m-d`) or month (`Y-m`) of the Revenue chart with the total of every code. A period without lines is 0.00.
 */
class RevenueChartPeriodData extends Data
{
    public function __construct(
        public string $period,
        public string $amount,
    ) {}
}

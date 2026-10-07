<?php

namespace App\Services\Reports;

use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The folio lines of one transaction code posted on one business date, summed.
 */
class DailyRevenueRow
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly int $transactionCodeId,
        public readonly Money $amount,
        public readonly int $lineCount,
    ) {}
}

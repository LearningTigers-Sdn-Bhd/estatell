<?php

namespace App\Services\Reports;

use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The folio lines of one transaction code posted on one business date, summed. `quantity` is the net quantity charged:
 * charge lines add theirs and their reversals take it back. An item charged per guest posts one per guest, so for it
 * the quantity is the pax.
 */
class DailyRevenueRow
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly int $transactionCodeId,
        public readonly Money $amount,
        public readonly int $lineCount,
        public readonly int $quantity = 0,
    ) {}
}

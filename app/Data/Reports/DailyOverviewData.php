<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * The eight cards of Daily report › Overview. Revenue is accrual (the folio ledger by posting date); the cashier
 * figures are cash flow (the Completed payments and refunds). Money is a decimal string with two decimals. `refunded`
 * is negative.
 */
class DailyOverviewData extends Data
{
    public function __construct(
        public int $bookings,
        public string $totalCharges,
        public string $adjustments,
        public string $netRevenue,
        public int $movements,
        public string $collected,
        public string $refunded,
        public string $netAtDesk,
    ) {}
}

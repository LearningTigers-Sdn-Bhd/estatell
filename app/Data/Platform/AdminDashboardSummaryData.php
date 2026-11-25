<?php

namespace App\Data\Platform;

use App\Data\Commissions\CommissionTotalsData;
use Spatie\LaravelData\Data;

/**
 * The admin dashboard cards: properties by status, the monthly charts, the next payout and the latest properties.
 */
class AdminDashboardSummaryData extends Data
{
    /**
     * @param  list<AdminDashboardMonthData>  $months
     * @param  list<AdminRecentPropertyData>  $recent
     */
    public function __construct(
        public int $live,
        public int $onboarding,
        public int $pendingApproval,
        public int $suspended,
        public CommissionTotalsData $commissions,
        public array $months,
        public array $recent,
    ) {}
}

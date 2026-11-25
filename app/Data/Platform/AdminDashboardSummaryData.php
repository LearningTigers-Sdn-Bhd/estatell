<?php

namespace App\Data\Platform;

use App\Data\Commissions\CommissionTotalsData;
use Spatie\LaravelData\Data;

/**
 * The admin dashboard tiles: properties by status, this month, commissions and the latest properties.
 */
class AdminDashboardSummaryData extends Data
{
    /**
     * @param  list<AdminRecentPropertyData>  $recent
     */
    public function __construct(
        public int $live,
        public int $onboarding,
        public int $pendingApproval,
        public int $suspended,
        public int $newThisMonth,
        public int $activePartners,
        public CommissionTotalsData $commissions,
        public array $recent,
    ) {}
}

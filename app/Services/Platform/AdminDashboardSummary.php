<?php

namespace App\Services\Platform;

use App\Data\Platform\AdminDashboardSummaryData;
use App\Data\Platform\AdminRecentPropertyData;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Services\Commissions\CommissionTotals;

/**
 * The admin dashboard cards: properties by status, the next payout, the monthly charts and the latest
 * properties. See docs/01-platform/10-admin-dashboard.md#cards.
 */
class AdminDashboardSummary
{
    private const int RECENT = 5;

    public function __construct(
        private CommissionTotals $commissionTotals,
        private AdminDashboardTrends $trends,
    ) {}

    public function get(): AdminDashboardSummaryData
    {
        $counts = Property::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (PropertyStatus $status): int => (int) ($counts[$status->value] ?? 0);

        return new AdminDashboardSummaryData(
            live: $count(PropertyStatus::Live),
            onboarding: $count(PropertyStatus::Onboarding),
            pendingApproval: $count(PropertyStatus::PendingApproval),
            suspended: $count(PropertyStatus::Suspended),
            commissions: $this->commissionTotals->for(),
            months: $this->trends->months(),
            recent: $this->recent(),
        );
    }

    /**
     * The latest properties by sign-up or go-live, whichever came last.
     *
     * @return list<AdminRecentPropertyData>
     */
    private function recent(): array
    {
        return array_values(Property::query()
            ->orderByRaw('greatest(created_at, coalesce(went_live_at, created_at)) desc')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get(['id', 'code', 'name', 'status', 'created_at', 'went_live_at'])
            ->map(fn (Property $property): AdminRecentPropertyData => AdminRecentPropertyData::fromModel($property))
            ->all());
    }
}

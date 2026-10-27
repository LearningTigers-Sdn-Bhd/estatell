<?php

namespace App\Services\Reports;

use App\Data\Reports\DailyOverviewData;
use App\Data\Reports\ReportFilters;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * The cards of Daily report › Overview, worked out from the same rows as the Revenue and Cashier activity tabs, so the
 * figures match the tables. Total charges is the charge columns plus the taxes; Net revenue adds the adjustments.
 * Bookings with revenue counts distinct reservations over the range. Movements counts the Completed payment rows.
 * See docs/22-reports/02-guest-list-and-daily-report.md#overview.
 */
class DailyOverview
{
    public function __construct(
        private DailyRevenueReport $revenue,
        private DailyRevenueQuery $query,
        private PaymentsReport $payments,
    ) {}

    public function forDates(Property $property, ReportFilters $filters): DailyOverviewData
    {
        $totals = $this->revenue->totals($this->revenue->rows($property, $filters))->figures;
        $adjustments = BigDecimal::of((string) $totals['adjustments']);
        $net = BigDecimal::of((string) $totals['total']);

        $completed = array_values(array_filter(
            $this->payments->rows($property, $filters),
            fn ($row): bool => $row->countsInTotals(),
        ));
        $collected = BigDecimal::zero();
        $refunded = BigDecimal::zero();

        foreach ($completed as $row) {
            $amount = BigDecimal::of($row->amount);

            if ($amount->isNegative()) {
                $refunded = $refunded->plus($amount);
            } else {
                $collected = $collected->plus($amount);
            }
        }

        return new DailyOverviewData(
            bookings: $this->query->bookingCountBetween($property, CarbonImmutable::parse($filters->from), CarbonImmutable::parse($filters->to)),
            totalCharges: $this->format($net->minus($adjustments)),
            adjustments: $this->format($adjustments),
            netRevenue: $this->format($net),
            movements: count($completed),
            collected: $this->format($collected),
            refunded: $this->format($refunded),
            netAtDesk: $this->format($collected->plus($refunded)),
        );
    }

    private function format(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }
}

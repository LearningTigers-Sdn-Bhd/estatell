<?php

namespace App\Services\Reports;

use App\Data\Reports\DepositLiabilityRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The folios in credit on one date: a balance below 0 on the date, from `FolioBalancesOnDate`. That is an advance for
 * a stay not yet arrived, an overpaid in-house folio, or a departed guest owed a refund. `held` is the credit and
 * `deposit` the security deposit still held, net of its refunds. Status is today's status.
 * See docs/22-reports/01-core-reports.md.
 */
class DepositLiabilityReport implements HasNote, HasTotals, Report
{
    public function __construct(private FolioReportRows $folioRows) {}

    /**
     * @param  list<int|string>|null  $rowKeys  Folio ids.
     * @return list<DepositLiabilityRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        return array_map(
            fn (FolioReportEntry $entry): DepositLiabilityRow => DepositLiabilityRow::fromEntry($entry),
            $this->folioRows->entries($property, $filters, false, $rowKeys),
        );
    }

    public function note(Property $property, ReportFilters $filters): ?string
    {
        return $this->folioRows->note($property, $filters);
    }

    /**
     * The sums of received, refunded, charged, deposit and held.
     *
     * @param  list<DepositLiabilityRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'folio' => null,
            'reservation' => null,
            'payer' => null,
            'arrival' => null,
            'departure' => null,
            'status' => null,
            'received' => $this->sum($rows, fn (DepositLiabilityRow $row): string => $row->received),
            'refunded' => $this->sum($rows, fn (DepositLiabilityRow $row): string => $row->refunded),
            'charged' => $this->sum($rows, fn (DepositLiabilityRow $row): string => $row->charged),
            'deposit' => $this->sum($rows, fn (DepositLiabilityRow $row): string => $row->deposit),
            'held' => $this->sum($rows, fn (DepositLiabilityRow $row): string => $row->held),
        ]);
    }

    /**
     * @param  list<DepositLiabilityRow>  $rows
     * @param  callable(DepositLiabilityRow): string  $amount
     */
    private function sum(array $rows, callable $amount): string
    {
        return (string) array_reduce($rows, fn (BigDecimal $sum, DepositLiabilityRow $row): BigDecimal => $sum->plus($amount($row)), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
    }
}

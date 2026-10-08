<?php

namespace App\Services\Reports;

use App\Data\Reports\OutstandingBalanceRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The folios that owe on one date: a balance above 0 on the date, from `FolioBalancesOnDate`. A folio settled to the
 * city ledger is at 0 and is not listed (the company's debt stays in City ledger aging). Status is today's status.
 * See docs/22-reports/01-core-reports.md.
 */
class OutstandingBalanceReport implements HasNote, HasTotals, Report
{
    public function __construct(private FolioReportRows $folioRows) {}

    /**
     * @param  list<int|string>|null  $rowKeys  Folio ids.
     * @return list<OutstandingBalanceRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        return array_map(
            fn (FolioReportEntry $entry): OutstandingBalanceRow => OutstandingBalanceRow::fromEntry($entry),
            $this->folioRows->entries($property, $filters, true, $rowKeys),
        );
    }

    public function note(Property $property, ReportFilters $filters): ?string
    {
        return $this->folioRows->note($property, $filters);
    }

    /**
     * The sums of charges, payments and balance.
     *
     * @param  list<OutstandingBalanceRow>  $rows
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
            'charges' => $this->sum($rows, fn (OutstandingBalanceRow $row): string => $row->charges),
            'payments' => $this->sum($rows, fn (OutstandingBalanceRow $row): string => $row->payments),
            'balance' => $this->sum($rows, fn (OutstandingBalanceRow $row): string => $row->balance),
            'days' => null,
        ]);
    }

    /**
     * @param  list<OutstandingBalanceRow>  $rows
     * @param  callable(OutstandingBalanceRow): string  $amount
     */
    private function sum(array $rows, callable $amount): string
    {
        return (string) array_reduce($rows, fn (BigDecimal $sum, OutstandingBalanceRow $row): BigDecimal => $sum->plus($amount($row)), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
    }
}

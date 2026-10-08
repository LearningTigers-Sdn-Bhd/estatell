<?php

namespace App\Services\Reports;

use App\Data\Reports\PaymentRow;
use App\Data\Reports\ReportBreakdown;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Models\ArPayment;
use App\Models\Payment;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments and refunds received in the range, by business date: the folio payments (the posting date of their ledger
 * line) and the city ledger payments (`received_on`). A refund is a negative amount. Every row shows, but only
 * Completed payments count in the totals and in the total for each method: Pending, Failed and Voided rows add
 * nothing. See docs/22-reports/01-core-reports.md.
 */
class PaymentsReport implements HasBreakdown, HasTotals, Report
{
    /**
     * @param  list<int|string>|null  $rowKeys  `p:{id}` for a folio payment, `ar:{id}` for a city ledger payment.
     * @return list<PaymentRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $folioIds = $this->idsWithPrefix($rowKeys, 'p');
        $arIds = $this->idsWithPrefix($rowKeys, 'ar');
        $rows = [];

        if ($folioIds === null || $folioIds !== []) {
            $payments = Payment::query()
                ->join('folio_lines', 'folio_lines.id', '=', 'payments.folio_line_id')
                ->where('payments.property_id', $property->id)
                ->whereBetween('folio_lines.posting_date', [$filters->from, $filters->to])
                ->when($folioIds !== null, fn (Builder $query) => $query->whereIn('payments.id', $folioIds))
                ->select('payments.*', 'folio_lines.posting_date as business_date')
                ->with(['folio.reservation', 'paymentMethod', 'receiver'])
                ->orderBy('folio_lines.posting_date')
                ->orderBy('payments.id')
                ->get();

            foreach ($payments as $payment) {
                $rows[] = PaymentRow::fromPayment($payment, substr((string) $payment->getAttribute('business_date'), 0, 10));
            }
        }

        if ($arIds === null || $arIds !== []) {
            $arPayments = ArPayment::query()
                ->where('property_id', $property->id)
                ->whereBetween('received_on', [$filters->from, $filters->to])
                ->when($arIds !== null, fn (Builder $query) => $query->whereIn('id', $arIds))
                ->with(['company', 'paymentMethod', 'receiver'])
                ->orderBy('received_on')
                ->orderBy('id')
                ->get();

            foreach ($arPayments as $arPayment) {
                $rows[] = PaymentRow::fromArPayment($arPayment);
            }
        }

        return $rows;
    }

    /**
     * The amount of the Completed rows. Refunds are already negative.
     *
     * @param  list<PaymentRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'date' => null,
            'receipt' => null,
            'payer' => null,
            'method' => null,
            'status' => null,
            'staff' => null,
            'amount' => $this->format($this->sum($this->completed($rows))),
            'reference' => null,
        ]);
    }

    /**
     * The count and the amount of the Completed rows for each payment method, by method name.
     *
     * @param  list<PaymentRow>  $rows
     */
    public function breakdown(array $rows): ReportBreakdown
    {
        $byMethod = [];

        foreach ($this->completed($rows) as $row) {
            $byMethod[$row->method][] = $row;
        }

        ksort($byMethod, SORT_NATURAL | SORT_FLAG_CASE);

        $lines = [];

        foreach ($byMethod as $method => $methodRows) {
            $lines[] = [(string) $method, (string) count($methodRows), $this->display($this->sum($methodRows))];
        }

        $completed = $this->completed($rows);

        return new ReportBreakdown(
            title: __('Total by method'),
            headings: [__('Method'), __('Count'), __('Amount')],
            rows: $lines,
            total: [__('Total'), (string) count($completed), $this->display($this->sum($completed))],
        );
    }

    /**
     * @param  list<PaymentRow>  $rows
     * @return list<PaymentRow>
     */
    private function completed(array $rows): array
    {
        return array_values(array_filter($rows, fn (PaymentRow $row): bool => $row->countsInTotals()));
    }

    /**
     * @param  list<PaymentRow>  $rows
     */
    private function sum(array $rows): BigDecimal
    {
        return array_reduce($rows, fn (BigDecimal $sum, PaymentRow $row): BigDecimal => $sum->plus($row->amount), BigDecimal::zero());
    }

    private function format(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }

    private function display(BigDecimal $amount): string
    {
        return number_format((float) $this->format($amount), 2);
    }

    /**
     * The ids in the row keys that start with the prefix, or null when no keys were asked for (every row).
     *
     * @param  list<int|string>|null  $rowKeys
     * @return list<int>|null
     */
    private function idsWithPrefix(?array $rowKeys, string $prefix): ?array
    {
        if ($rowKeys === null) {
            return null;
        }

        $ids = [];

        foreach ($rowKeys as $key) {
            if (preg_match('/^'.$prefix.':(\d+)$/', (string) $key, $match) === 1) {
                $ids[] = (int) $match[1];
            }
        }

        return $ids;
    }
}

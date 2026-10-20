<?php

namespace App\Services\Reports;

use App\Data\Reports\PaymentRow;
use App\Data\Reports\ReportBreakdown;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Enums\PaymentMethodType;
use App\Enums\ReportGroup;
use App\Models\ArPayment;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments and refunds received in the range, by business date: the folio payments (the posting date of their ledger
 * line) and the city ledger payments (`received_on`). A refund is a negative amount. Every row shows, but only
 * Completed payments count in the totals and in the summaries (by method or by staff, with In, Out and Net): Pending,
 * Failed and Voided rows add nothing. The `staff` filter keeps one receiver, and `refundsOnly` keeps only the refunds
 * (and so no city ledger rows). Payments of the Old system method (and their refunds) are left out of the rows, the totals, the staff list and both summaries.
 * See docs/22-reports/01-core-reports.md.
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
                ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
                ->where('payment_methods.type', '!=', PaymentMethodType::OldSystem->value)
                ->where('payments.property_id', $property->id)
                ->whereBetween('folio_lines.posting_date', [$filters->from, $filters->to])
                ->when($folioIds !== null, fn (Builder $query) => $query->whereIn('payments.id', $folioIds))
                ->when($filters->staff !== null, fn (Builder $query) => $query->where('payments.received_by', $filters->staff))
                ->when($filters->refundsOnly, fn (Builder $query) => $query->whereNotNull('payments.refund_of_id'))
                ->select('payments.*', 'folio_lines.posting_date as business_date')
                ->with(['folio.reservation', 'paymentMethod', 'receiver', 'refundOf:id,receipt_number'])
                ->orderBy('folio_lines.posting_date')
                ->orderBy('payments.id')
                ->get();

            foreach ($payments as $payment) {
                $rows[] = PaymentRow::fromPayment($payment, substr((string) $payment->getAttribute('business_date'), 0, 10));
            }
        }

        if (! $filters->refundsOnly && ($arIds === null || $arIds !== [])) {
            $arPayments = ArPayment::query()
                ->where('property_id', $property->id)
                ->whereBetween('received_on', [$filters->from, $filters->to])
                ->when($arIds !== null, fn (Builder $query) => $query->whereIn('id', $arIds))
                ->when($filters->staff !== null, fn (Builder $query) => $query->where('received_by', $filters->staff))
                ->with(['company', 'paymentMethod', 'receiver'])
                ->orderBy('received_on')
                ->orderBy('id')
                ->get();

            foreach ($arPayments as $arPayment) {
                $rows[] = PaymentRow::fromArPayment($arPayment);
            }
        }

        usort($rows, fn (PaymentRow $a, PaymentRow $b): int => $a->date <=> $b->date);

        return $rows;
    }

    /**
     * The staff for the Staff filter: the users who received a folio or city ledger payment in the dates, by name.
     * It ignores the staff and refunds filters, so the list does not shrink after a pick.
     *
     * @return list<array{value: string, label: string}>
     */
    public function staffOptions(Property $property, ReportFilters $filters): array
    {
        $folio = Payment::query()
            ->join('folio_lines', 'folio_lines.id', '=', 'payments.folio_line_id')
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payment_methods.type', '!=', PaymentMethodType::OldSystem->value)
            ->where('payments.property_id', $property->id)
            ->whereBetween('folio_lines.posting_date', [$filters->from, $filters->to])
            ->select('payments.received_by');

        $cityLedger = ArPayment::query()
            ->where('property_id', $property->id)
            ->whereBetween('received_on', [$filters->from, $filters->to])
            ->select('received_by');

        return array_values(User::query()
            ->where('property_id', $property->id)
            ->whereIn('id', $folio->union($cityLedger))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => (string) $user->id, 'label' => $user->name])
            ->all());
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
     * The summary under the table, by the `group` of the filters. By method (the default): the count, In, Out and Net of
     * the Completed rows for each payment method. By staff (the cash-up): the same for each method of each staff
     * member, with a subtotal row for each staff member. In is the Completed payments, Out the Completed refunds
     * (negative) and Net is In plus Out.
     *
     * @param  list<PaymentRow>  $rows
     */
    public function breakdown(Property $property, array $rows, ReportFilters $filters): ReportBreakdown
    {
        return $filters->group === ReportGroup::Staff ? $this->byStaff($rows) : $this->byMethod($rows);
    }

    /**
     * @param  list<PaymentRow>  $rows
     */
    private function byMethod(array $rows): ReportBreakdown
    {
        $completed = $this->completed($rows);
        $lines = [];

        foreach ($this->groupedBy($completed, fn (PaymentRow $row): string => $row->method) as $method => $methodRows) {
            $lines[] = [(string) $method, ...$this->figures($methodRows)];
        }

        return new ReportBreakdown(
            title: __('Total by method'),
            headings: [__('Method'), __('Count'), __('In'), __('Out'), __('Net')],
            rows: $lines,
            total: [__('Total'), ...$this->figures($completed)],
        );
    }

    /**
     * @param  list<PaymentRow>  $rows
     */
    private function byStaff(array $rows): ReportBreakdown
    {
        $completed = $this->completed($rows);
        $byStaff = [];

        foreach ($completed as $row) {
            $byStaff[$row->staff_id][] = $row;
        }

        uasort($byStaff, fn (array $a, array $b): int => strcasecmp($a[0]->staff, $b[0]->staff) ?: $a[0]->staff_id <=> $b[0]->staff_id);

        $lines = [];
        $subtotalRows = [];

        foreach ($byStaff as $staffRows) {
            $name = $staffRows[0]->staff;

            foreach ($this->groupedBy($staffRows, fn (PaymentRow $row): string => $row->method) as $method => $methodRows) {
                $lines[] = [$name, (string) $method, ...$this->figures($methodRows)];
            }

            $subtotalRows[] = count($lines);
            $lines[] = [__(':name subtotal', ['name' => $name]), '', ...$this->figures($staffRows)];
        }

        return new ReportBreakdown(
            title: __('Cash-up by staff'),
            headings: [__('Staff'), __('Method'), __('Count'), __('In'), __('Out'), __('Net')],
            rows: $lines,
            total: [__('Total'), '', ...$this->figures($completed)],
            subtotalRows: $subtotalRows,
        );
    }

    /**
     * The rows by a text key, in natural order of the key.
     *
     * @param  list<PaymentRow>  $rows
     * @param  callable(PaymentRow): string  $key
     * @return array<string, list<PaymentRow>>
     */
    private function groupedBy(array $rows, callable $key): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$key($row)][] = $row;
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return $groups;
    }

    /**
     * The display text of the Count, In, Out and Net of Completed rows.
     *
     * @param  list<PaymentRow>  $rows
     * @return list<string>
     */
    private function figures(array $rows): array
    {
        $in = $this->sum(array_filter($rows, fn (PaymentRow $row): bool => ! BigDecimal::of($row->amount)->isNegative()));
        $out = $this->sum(array_filter($rows, fn (PaymentRow $row): bool => BigDecimal::of($row->amount)->isNegative()));

        return [(string) count($rows), $this->display($in), $this->display($out), $this->display($in->plus($out))];
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
     * @param  array<int, PaymentRow>  $rows
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

<?php

namespace App\Services\CityLedger;

use App\Data\CityLedger\AgingReportData;
use App\Data\CityLedger\AgingRowData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * What companies owe by days past due, as at a date: Current (not yet due), 1-30, 31-60, 61-90 and over 90 days past
 * due. Only Open invoices with a balance above 0 count, each by its own balance (amount + credit notes that are not
 * Void - active allocations). A company's unallocated payments show as one negative Credit column. A company with
 * nothing owed and no credit is left out. It writes nothing and runs a constant number of queries.
 * See docs/15-city-ledger/04-credit-control-and-reports.md#aging-report.
 */
class AgingReport
{
    private const array BUCKETS = ['current', 'days1to30', 'days31to60', 'days61to90', 'over90'];

    public function __construct(private ArInvoiceBalance $arInvoiceBalance) {}

    public function build(Property $property, CarbonImmutable $asAt): AgingReportData
    {
        $rows = $this->rows($property, $asAt, null);
        $sums = array_fill_keys([...self::BUCKETS, 'credit', 'total'], BigDecimal::zero());

        foreach ($rows as $row) {
            $figures = ['current' => $row->current, 'days1to30' => $row->days1to30, 'days31to60' => $row->days31to60, 'days61to90' => $row->days61to90, 'over90' => $row->over90, 'credit' => $row->credit, 'total' => $row->total];

            foreach ($figures as $key => $figure) {
                $sums[$key] = $sums[$key]->plus($figure);
            }
        }

        $totals = array_map(fn (BigDecimal $sum): string => (string) $sum->toScale(2), $sums);

        return new AgingReportData(
            asAt: $asAt->toDateString(),
            rows: $rows,
            totals: new AgingRowData(null, __('Total'), ...$totals),
        );
    }

    /**
     * One company's row as at a date, with zeros when it owes nothing.
     */
    public function forCompany(Company $company, CarbonImmutable $asAt): AgingRowData
    {
        return $this->rows($company->property, $asAt, $company)[0]
            ?? new AgingRowData($company->id, $company->name, '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00');
    }

    /**
     * @return list<AgingRowData>
     */
    private function rows(Property $property, CarbonImmutable $asAt, ?Company $company): array
    {
        $buckets = [];

        $invoices = ArInvoice::query()
            ->where('ar_invoices.property_id', $property->id)
            ->where('ar_invoices.type', ArInvoiceType::Invoice)
            ->where('ar_invoices.status', ArInvoiceStatus::Open)
            ->when($company !== null, fn ($query) => $query->where('ar_invoices.company_id', $company->id))
            ->addSelect('ar_invoices.*')
            ->selectSub(
                ArInvoice::query()
                    ->from('ar_invoices as credits')
                    ->selectRaw('COALESCE(SUM(credits.amount), 0)')
                    ->whereColumn('credits.credit_for_invoice_id', 'ar_invoices.id')
                    ->where('credits.status', '<>', ArInvoiceStatus::Void->value),
                'credited',
            )
            ->selectSub(
                ArAllocation::query()
                    ->selectRaw('COALESCE(SUM(ar_allocations.amount), 0)')
                    ->whereColumn('ar_allocations.ar_invoice_id', 'ar_invoices.id')
                    ->whereNull('ar_allocations.reversed_at'),
                'allocated',
            )
            ->get();

        foreach ($invoices as $invoice) {
            $balance = $this->arInvoiceBalance->fromFigures((string) $invoice->amount, (string) $invoice->getAttribute('credited'), (string) $invoice->getAttribute('allocated'));

            if (BigDecimal::of($balance)->isNegativeOrZero()) {
                continue;
            }

            $bucket = $this->bucket($invoice->due_on === null ? 0 : (int) $invoice->due_on->startOfDay()->diffInDays($asAt->startOfDay(), false));
            $buckets[$invoice->company_id][$bucket] = (string) BigDecimal::of($buckets[$invoice->company_id][$bucket] ?? '0.00')->plus($balance)->toScale(2);
        }

        $credits = $this->credits($property, $company);
        $companyIds = array_values(array_unique([...array_keys($buckets), ...array_keys($credits)]));
        $names = Company::query()->whereIn('id', $companyIds)->pluck('name', 'id');

        $rows = array_map(function (int $id) use ($buckets, $credits, $names): AgingRowData {
            $owed = $buckets[$id] ?? [];
            $credit = BigDecimal::of($credits[$id] ?? '0.00')->negated();
            $total = array_reduce(self::BUCKETS, fn (BigDecimal $sum, string $key): BigDecimal => $sum->plus($owed[$key] ?? '0.00'), $credit);

            return new AgingRowData(
                $id,
                (string) $names->get($id, ''),
                $owed['current'] ?? '0.00',
                $owed['days1to30'] ?? '0.00',
                $owed['days31to60'] ?? '0.00',
                $owed['days61to90'] ?? '0.00',
                $owed['over90'] ?? '0.00',
                (string) $credit->toScale(2),
                (string) $total->toScale(2),
            );
        }, $companyIds);

        usort($rows, fn (AgingRowData $a, AgingRowData $b): int => strcasecmp($a->companyName, $b->companyName));

        return $rows;
    }

    /**
     * The bucket of an invoice that is the given number of days past due (0 or less means not yet due).
     */
    private function bucket(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0 => 'current',
            $daysPastDue <= 30 => 'days1to30',
            $daysPastDue <= 60 => 'days31to60',
            $daysPastDue <= 90 => 'days61to90',
            default => 'over90',
        };
    }

    /**
     * Unallocated money of payments that are not voided, by company, above 0 only.
     *
     * @return array<int, string>
     */
    private function credits(Property $property, ?Company $company): array
    {
        $payments = ArPayment::query()
            ->where('ar_payments.property_id', $property->id)
            ->whereNull('ar_payments.voided_at')
            ->when($company !== null, fn ($query) => $query->where('ar_payments.company_id', $company->id))
            ->groupBy('ar_payments.company_id')
            ->selectRaw('ar_payments.company_id, SUM(ar_payments.amount) AS total')
            ->pluck('total', 'company_id');

        $allocated = ArAllocation::query()
            ->join('ar_payments', 'ar_payments.id', '=', 'ar_allocations.ar_payment_id')
            ->where('ar_payments.property_id', $property->id)
            ->whereNull('ar_payments.voided_at')
            ->whereNull('ar_allocations.reversed_at')
            ->when($company !== null, fn ($query) => $query->where('ar_payments.company_id', $company->id))
            ->groupBy('ar_payments.company_id')
            ->selectRaw('ar_payments.company_id, SUM(ar_allocations.amount) AS total')
            ->pluck('total', 'company_id');

        $credits = [];

        foreach ($payments as $companyId => $total) {
            $left = BigDecimal::of((string) $total)->minus((string) ($allocated[$companyId] ?? '0'));

            if ($left->isPositive()) {
                $credits[(int) $companyId] = (string) $left->toScale(2);
            }
        }

        return $credits;
    }
}

<?php

namespace App\Services\CityLedger;

use App\Data\CityLedger\StatementData;
use App\Data\CityLedger\StatementLineData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The statement of account of one company between two dates: the opening balance, then its invoices (+), credit
 * notes (-) and payments (-) in date order, and the closing balance. Void documents and voided payments are left
 * out, and a payment shows once at its full amount, not by allocation. The opening balance is what the documents
 * issued and payments received before the start date add up to. It writes nothing.
 * See docs/15-city-ledger/04-credit-control-and-reports.md#statement-of-account.
 */
class StatementBuilder
{
    private const array ORDER = ['invoice' => 0, 'credit_note' => 1, 'payment' => 2];

    public function __construct(private AgingReport $agingReport) {}

    public function build(Company $company, CarbonImmutable $from, CarbonImmutable $to): StatementData
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        $opening = $this->openingBalance($company, $from);
        $entries = [];

        $documents = ArInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', '<>', ArInvoiceStatus::Void)
            ->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])
            ->get();

        foreach ($documents as $document) {
            $isInvoice = $document->type === ArInvoiceType::Invoice;
            $entries[] = [
                'date' => $document->issued_on->toDateString(),
                'kind' => $isInvoice ? 'invoice' : 'credit_note',
                'label' => $document->type->label(),
                'number' => $document->number,
                'description' => $document->reason,
                'amount' => (string) $document->amount,
            ];
        }

        $payments = ArPayment::query()
            ->where('company_id', $company->id)
            ->whereNull('voided_at')
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->with('paymentMethod:id,name')
            ->get();

        foreach ($payments as $payment) {
            $entries[] = [
                'date' => $payment->received_on->toDateString(),
                'kind' => 'payment',
                'label' => __('Payment'),
                'number' => $payment->receipt_number,
                'description' => trim($payment->paymentMethod->name.' '.($payment->reference ?? '')),
                'amount' => '-'.$payment->amount,
            ];
        }

        usort($entries, fn (array $a, array $b): int => [$a['date'], self::ORDER[$a['kind']], $a['number']] <=> [$b['date'], self::ORDER[$b['kind']], $b['number']]);

        $balance = $opening;
        $lines = [];

        foreach ($entries as $entry) {
            $balance = bcadd($balance, $entry['amount'], 2);
            $lines[] = new StatementLineData($entry['date'], $entry['kind'], $entry['label'], $entry['number'], $entry['description'], $entry['amount'], $balance);
        }

        return new StatementData(
            from: $from->toDateString(),
            to: $to->toDateString(),
            openingBalance: $opening,
            lines: $lines,
            closingBalance: $balance,
            aging: $this->agingReport->forCompany($company, $to),
        );
    }

    /**
     * Invoices plus credit notes issued before the date, minus payments received before it.
     */
    private function openingBalance(Company $company, CarbonImmutable $from): string
    {
        $documents = (string) ArInvoice::query()
            ->where('company_id', $company->id)
            ->where('status', '<>', ArInvoiceStatus::Void)
            ->where('issued_on', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(14, 2) AS total')
            ->value('total');

        $payments = (string) ArPayment::query()
            ->where('company_id', $company->id)
            ->whereNull('voided_at')
            ->where('received_on', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(14, 2) AS total')
            ->value('total');

        return bcsub($documents, $payments, 2);
    }
}

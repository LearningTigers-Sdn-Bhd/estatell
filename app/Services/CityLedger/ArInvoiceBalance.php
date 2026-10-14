<?php

namespace App\Services\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Models\ArAllocation;
use App\Models\ArInvoice;

/**
 * Calculates what an AR invoice still owes: its amount plus its credit notes that are not Void (negative), minus the
 * active allocations of AR payments. Nothing is stored. See docs/15-city-ledger/02-ar-invoices.md.
 */
class ArInvoiceBalance
{
    /**
     * The balance as a decimal string with two places. A credit note has no balance of its own: it answers 0.00.
     */
    public function forInvoice(ArInvoice $invoice): string
    {
        if ($invoice->credit_for_invoice_id !== null) {
            return '0.00';
        }

        $credits = (string) ArInvoice::query()
            ->where('credit_for_invoice_id', $invoice->id)
            ->where('status', '<>', ArInvoiceStatus::Void)
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(14, 2) AS credits')
            ->value('credits');

        $allocated = (string) ArAllocation::query()
            ->where('ar_invoice_id', $invoice->id)
            ->active()
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(14, 2) AS allocated')
            ->value('allocated');

        return bcsub(bcadd((string) $invoice->amount, $credits, 2), $allocated, 2);
    }

    /**
     * The receipt numbers of the invoice's active allocations, oldest first. A refused credit note names them.
     *
     * @return list<string>
     */
    public function activeReceiptNumbers(ArInvoice $invoice): array
    {
        return array_values(ArAllocation::query()
            ->where('ar_invoice_id', $invoice->id)
            ->active()
            ->with('payment:id,receipt_number')
            ->orderBy('id')
            ->get()
            ->map(fn (ArAllocation $allocation): string => $allocation->payment->receipt_number)
            ->all());
    }
}

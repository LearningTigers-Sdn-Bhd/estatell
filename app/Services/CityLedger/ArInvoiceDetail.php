<?php

namespace App\Services\CityLedger;

use App\Data\CityLedger\ArAllocationRowData;
use App\Data\CityLedger\ArInvoiceData;
use App\Data\CityLedger\ArInvoiceLineData;
use App\Data\CityLedger\ArInvoiceRowData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\PermissionEnum;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\FolioLine;
use App\Models\User;

/**
 * Reads one invoice or credit note for its page: the row, its origin, its credit notes and the folio lines it was
 * made from. It writes nothing. See docs/15-city-ledger/02-ar-invoices.md#pages.
 */
class ArInvoiceDetail
{
    public function __construct(private ArInvoiceBalance $arInvoiceBalance, private ArInvoiceDirectory $directory) {}

    public function build(ArInvoice $invoice, User $user): ArInvoiceData
    {
        $invoice->loadMissing(['company:id,name', 'folio.reservation:id,reference', 'creditFor:id,number', 'creditNotes.company:id,name']);
        $today = $this->directory->today($invoice->property);
        $isInvoice = $invoice->type === ArInvoiceType::Invoice;
        $balance = $this->arInvoiceBalance->forInvoice($invoice);
        $notes = $invoice->creditNotes->sortBy('id')->values();
        $credited = $notes->where('status', '<>', ArInvoiceStatus::Void)->reduce(fn (string $sum, ArInvoice $note): string => bcadd($sum, (string) $note->amount, 2), '0.00');

        $allocations = $isInvoice ? $this->allocations($invoice, $user) : [];

        return new ArInvoiceData(
            row: ArInvoiceRowData::fromModel($invoice, $balance, $today),
            reason: $invoice->reason,
            creditForNumber: $invoice->creditFor?->number,
            creditForId: $invoice->credit_for_invoice_id,
            folioNumber: $invoice->folio?->number,
            reservationId: $invoice->folio?->reservation?->id,
            reservationReference: $invoice->folio?->reservation?->reference,
            credited: $credited,
            creditNotes: $notes->map(fn (ArInvoice $note): ArInvoiceRowData => ArInvoiceRowData::fromModel($note, null, $today))->all(),
            lines: $this->lines($invoice),
            allocations: $allocations,
            creditBlockedBy: array_values(array_filter($allocations, fn (ArAllocationRowData $allocation): bool => ! $allocation->isReversed)),
            canVoid: $isInvoice
                ? $invoice->status === ArInvoiceStatus::Open && $notes->where('status', '<>', ArInvoiceStatus::Void)->isEmpty() && $user->can(PermissionEnum::CityLedgerInvoice->value)
                : $invoice->status === ArInvoiceStatus::Settled && $user->can(PermissionEnum::CityLedgerCreditNote->value),
            canCreditNote: $isInvoice && $invoice->status !== ArInvoiceStatus::Void && $user->can(PermissionEnum::CityLedgerCreditNote->value),
        );
    }

    /**
     * The allocations of AR payments to the invoice, newest first, reversed ones included.
     *
     * @return list<ArAllocationRowData>
     */
    private function allocations(ArInvoice $invoice, User $user): array
    {
        $mayReverse = $user->can(PermissionEnum::CityLedgerAllocate->value);

        return ArAllocation::query()
            ->where('ar_invoice_id', $invoice->id)
            ->with('payment:id,company_id,receipt_number,received_on')
            ->latest('id')
            ->get()
            ->map(fn (ArAllocation $allocation): ArAllocationRowData => ArAllocationRowData::fromModel($allocation, $mayReverse))
            ->all();
    }

    /**
     * The folio lines the invoice was made from, in ledger order, without the City ledger line that moved the balance.
     *
     * @return list<ArInvoiceLineData>
     */
    public function lines(ArInvoice $invoice): array
    {
        if ($invoice->folio_id === null) {
            return [];
        }

        return FolioLine::query()
            ->where('folio_id', $invoice->folio_id)
            ->where('idempotency_key', '<>', 'city-ledger:'.$invoice->folio_id)
            ->orderBy('id')
            ->get(['posting_date', 'description', 'amount'])
            ->map(fn (FolioLine $line): ArInvoiceLineData => new ArInvoiceLineData($line->posting_date->toDateString(), $line->description, (string) $line->amount))
            ->all();
    }
}

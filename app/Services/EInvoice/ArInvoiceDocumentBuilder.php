<?php

namespace App\Services\EInvoice;

use App\Actions\EInvoice\EInvoiceResult;
use App\Models\ArInvoice;

/**
 * Builds the e-invoice (`01`) of a City ledger invoice: the stay's own charge and tax lines, as on its folio, billed
 * to the company under the invoice's number. SST and tourism tax are reported as charged, not as exempt. A City ledger
 * invoice is made from a closed folio (`CreateArInvoiceFromFolio`) and takes the folio's invoice number, so the
 * e-invoice is the stay's sale, whatever part of it was paid before it was billed. It reads only.
 */
class ArInvoiceDocumentBuilder
{
    public function __construct(private FolioDocumentBuilder $folioDocumentBuilder) {}

    /**
     * @return array{format: string, document: string, documentHash: string, codeNumber: string, payload: array<string, mixed>, buyer: array<string, mixed>}
     *
     * @throws MissingEInvoiceDetails when the hotel or the company lacks a detail LHDN needs, or the invoice has no stay.
     */
    public function build(ArInvoice $invoice): array
    {
        if ($invoice->folio === null) {
            throw new MissingEInvoiceDetails(EInvoiceResult::REFUSAL_NOTHING_TO_REPORT, __('This City ledger invoice has no stay behind it, so it has no e-invoice.'));
        }

        return $this->folioDocumentBuilder->build($invoice->folio, BuyerResolver::forCompany($invoice->company, $invoice->property), $invoice->number);
    }
}

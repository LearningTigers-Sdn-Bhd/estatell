<?php

namespace App\Services\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;

/**
 * The e-invoice that holds a folio closed: its own e-invoice or a monthly summary it is in, while LHDN has it or is
 * checking it (not Rejected, not Cancelled). LHDN does not allow an issued e-invoice to change, so the folio must not
 * reopen and change under it: a late charge goes on a new folio, and a lower price takes a credit note. Within 72
 * hours of acceptance staff may cancel the e-invoice instead, which frees the folio. It reads only. See
 * docs/31-einvoice/01-lhdn-myinvois.md#document-rules.
 */
class FolioEInvoiceLock
{
    public function for(Folio $folio): ?EInvoiceSubmission
    {
        return EInvoiceSubmission::query()
            ->where('property_id', $folio->property_id)
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->active()
            ->where(fn ($query) => $query
                ->where('folio_id', $folio->id)
                ->orWhereHas('folios', fn ($folios) => $folios->whereKey($folio->id)))
            ->latest('id')
            ->first();
    }
}

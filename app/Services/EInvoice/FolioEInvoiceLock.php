<?php

namespace App\Services\EInvoice;

use App\Enums\ArInvoiceStatus;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Models\ArInvoice;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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

    /**
     * Narrow a folio query to the stays still to report to LHDN: not held by an e-invoice (`for`), and not billed to
     * a company on a City ledger invoice that is not Void, since that invoice's e-invoice reports the stay. The monthly
     * summary and the list of stays over RM10,000 both read it.
     *
     * @param  Builder<Folio>  $folios
     * @return Builder<Folio>
     */
    public function whereStillToReport(Builder $folios, int $propertyId): Builder
    {
        $ownEInvoice = EInvoiceSubmission::query()
            ->where('property_id', $propertyId)
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->whereNotNull('folio_id')
            ->active()
            ->select('folio_id');
        $inSummary = DB::table('e_invoice_submission_folios as link')
            ->join('e_invoice_submissions as summary', 'summary.id', '=', 'link.submission_id')
            ->where('summary.property_id', $propertyId)
            ->where('summary.document_type', EInvoiceDocumentType::Invoice->value)
            ->whereNotIn('summary.status', [EInvoiceStatus::Cancelled->value, EInvoiceStatus::Invalid->value])
            ->select('link.folio_id');
        $billed = ArInvoice::query()->where('property_id', $propertyId)->whereNotNull('folio_id')->where('status', '<>', ArInvoiceStatus::Void)->select('folio_id');

        return $folios
            ->whereNotIn('folios.id', $ownEInvoice)
            ->whereNotIn('folios.id', $inSummary)
            ->whereNotIn('folios.id', $billed);
    }
}

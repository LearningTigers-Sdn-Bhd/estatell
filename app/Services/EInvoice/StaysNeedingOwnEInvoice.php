<?php

namespace App\Services\EInvoice;

use App\Enums\ArInvoiceStatus;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioStatus;
use App\Models\ArInvoice;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Closed stays over RM10,000 that have no e-invoice yet. Since 1 January 2026 LHDN does not allow a sale over RM10,000
 * in a monthly summary, so each needs its own e-invoice with the guest's own tax number. A stay billed to a company is
 * e-invoiced from its City ledger invoice and is not listed. Only stays closed since e-invoicing was set up count. It
 * reads only. See docs/31-einvoice/01-lhdn-myinvois.md#stays-over-rm10000.
 */
class StaysNeedingOwnEInvoice
{
    public const float LIMIT = ConsolidatedBatchBuilder::MAX_INDIVIDUAL_STAY_AMOUNT;

    /**
     * Whether this folio's total is over the limit, so it cannot go in a monthly summary. Expects `lines.reversal`.
     */
    public static function isOverLimit(Folio $folio): bool
    {
        return ConsolidatedBatchBuilder::calculateFolioGrossAmount($folio) > self::LIMIT;
    }

    /**
     * @return list<array{folio_id: int, reservation_id: int, reference: string|null, guest: string|null, amount: string, closed_on: string|null}>
     */
    public function for(Property $property): array
    {
        $setting = $property->eInvoiceSetting;
        if ($setting === null || ! $setting->enabled) {
            return [];
        }

        $withEInvoice = EInvoiceSubmission::query()
            ->where('property_id', $property->id)
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->whereNotNull('folio_id')
            ->active()
            ->select('folio_id');
        $inSummary = DB::table('e_invoice_submission_folios')
            ->join('e_invoice_submissions', 'e_invoice_submissions.id', '=', 'e_invoice_submission_folios.submission_id')
            ->where('e_invoice_submissions.property_id', $property->id)
            ->whereNotIn('e_invoice_submissions.status', [EInvoiceStatus::Cancelled->value, EInvoiceStatus::Invalid->value])
            ->select('e_invoice_submission_folios.folio_id');
        $billed = ArInvoice::query()->whereNotNull('folio_id')->where('status', '<>', ArInvoiceStatus::Void)->select('folio_id');

        $folios = Folio::query()
            ->where('property_id', $property->id)
            ->where('status', FolioStatus::Closed)
            ->where('closed_at', '>=', $setting->created_at)
            ->whereNotIn('id', $withEInvoice)
            ->whereNotIn('id', $inSummary)
            ->whereNotIn('id', $billed)
            ->with(['lines.reversal', 'reservation:id,reference', 'payerGuest:id,full_name'])
            ->orderBy('closed_at')
            ->get();

        $stays = [];
        foreach ($folios as $folio) {
            if (! self::isOverLimit($folio)) {
                continue;
            }

            $stays[] = [
                'folio_id' => $folio->id,
                'reservation_id' => $folio->reservation_id,
                'reference' => $folio->reservation?->reference,
                'guest' => $folio->payerGuest?->full_name,
                'amount' => number_format(ConsolidatedBatchBuilder::calculateFolioGrossAmount($folio), 2, '.', ''),
                'closed_on' => $folio->closed_at?->toDateString(),
            ];
        }

        return $stays;
    }
}

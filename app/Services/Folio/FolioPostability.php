<?php

namespace App\Services\Folio;

use App\Actions\Folio\PostingResult;
use App\Enums\ArInvoiceStatus;
use App\Enums\FolioStatus;
use App\Models\ArInvoice;
use App\Models\Folio;

/**
 * Picks the refusal for a posting to a folio that is not Open: `folio_settled_to_city_ledger` when the folio is Closed
 * with an AR invoice that is not Void (it cannot reopen, so staff need another folio), otherwise `folio_not_open`.
 * It is called only on the refusal path. See docs/13-folio/03-routing.md#rules.
 */
class FolioPostability
{
    public function refusalFor(Folio $folio): ?string
    {
        if ($folio->status === FolioStatus::Open) {
            return null;
        }

        $settled = $folio->status === FolioStatus::Closed
            && ArInvoice::query()->where('folio_id', $folio->id)->where('status', '<>', ArInvoiceStatus::Void)->exists();

        return $settled ? PostingResult::REFUSAL_FOLIO_SETTLED : PostingResult::REFUSAL_FOLIO_NOT_OPEN;
    }
}

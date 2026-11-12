<?php

namespace App\Actions\EInvoice;

use App\Events\FolioClosed;
use App\Features\EInvoicing;
use App\Jobs\SubmitFolioEInvoiceJob;
use App\Models\Folio;
use App\Services\EInvoice\BuyerResolver;
use Laravel\Pennant\Feature;

/**
 * Listener for `FolioClosed`: queue the stay's e-invoice when the hotel turned on Send at check-out and the paying
 * guest gave their own tax number. A guest with no tax number of their own (including a foreign guest, who would get
 * LHDN's general foreign number) goes into the monthly summary instead; a company-paid folio is e-invoiced from its
 * City ledger invoice or by staff. `SubmitFolioEInvoice` makes every other check. The job is queued after the close
 * commits, so a check-out never waits on LHDN. See docs/31-einvoice/01-lhdn-myinvois.md#send-at-check-out.
 */
class QueueFolioEInvoiceOnClose
{
    public function __invoke(FolioClosed $event): void
    {
        $folio = Folio::query()->with(['property.eInvoiceSetting', 'payerGuest:id,tin'])->find($event->folioId);
        $setting = $folio?->property->eInvoiceSetting;

        if ($folio === null || $setting === null || ! $setting->enabled || ! $setting->auto_submit_on_checkout) {
            return;
        }

        if ($folio->payer_company_id !== null || ! $this->hasOwnTaxNumber($folio->payerGuest?->tin)) {
            return;
        }

        if (! Feature::for($folio->property)->active(EInvoicing::class)) {
            return;
        }

        SubmitFolioEInvoiceJob::dispatch($folio->id, $event->actorId)->afterCommit();
    }

    private function hasOwnTaxNumber(?string $tin): bool
    {
        $tin = strtoupper(trim((string) $tin));

        return $tin !== '' && ! in_array($tin, [BuyerResolver::FOREIGN_BUYER_TIN, BuyerResolver::GENERAL_PUBLIC_TIN], true);
    }
}

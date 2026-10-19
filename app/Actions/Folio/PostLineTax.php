<?php

namespace App\Actions\Folio;

use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\Catalogues\CalculatedTax;

class PostLineTax
{
    /**
     * Write one Tax line under a posted charge, with no checks. It sits on the charge's folio and takes the charge's
     * service date. The caller checks everything first and runs this inside its transaction. `AddLineTax` and
     * `RecalculateLineTax` call it. See docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
     */
    public function __invoke(User $actor, FolioLine $charge, CalculatedTax $tax, string $key, string $postingDate, int $manualBatchId): FolioLine
    {
        $line = new FolioLine([
            'transaction_code_id' => $tax->transactionCodeId,
            'kind' => TransactionKind::Tax,
            'description' => $tax->name,
            'quantity' => 1,
            'unit_amount' => (string) $tax->amount->getAmount(),
            'amount' => (string) $tax->amount->getAmount(),
            'parent_line_id' => $charge->id,
        ]);
        $line->folio_id = $charge->folio_id;
        $line->posting_date = $postingDate;
        $line->service_date = $charge->service_date;
        $line->manual_batch_id = $manualBatchId;
        $line->idempotency_key = $key;
        $line->user_id = $actor->id;
        $line->save();

        return $line->refresh();
    }
}

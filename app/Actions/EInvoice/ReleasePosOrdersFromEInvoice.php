<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use App\Models\PosOrder;
use Illuminate\Support\Facades\DB;

/**
 * A rejected or cancelled e-invoice no longer reports its POS orders: the history rows go inactive and the orders are free
 * for the next monthly summary or an own e-invoice. Runs when the submission's status changes.
 */
class ReleasePosOrdersFromEInvoice
{
    public function __invoke(EInvoiceSubmission $submission): void
    {
        if (! $submission->wasChanged('status') || ! in_array($submission->status, [EInvoiceStatus::Invalid, EInvoiceStatus::Cancelled], true)) {
            return;
        }

        DB::transaction(function () use ($submission): void {
            DB::table('e_invoice_submission_pos_orders')->where('submission_id', $submission->id)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
            PosOrder::query()->where('e_invoice_submission_id', $submission->id)->update(['e_invoice_submission_id' => null]);
        });
    }
}

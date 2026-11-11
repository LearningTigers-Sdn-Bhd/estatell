<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use App\Models\User;

/**
 * Sends a rejected stay e-invoice again with a corrected tax number, usually the one LHDN holds for the guest's IC
 * (Find the tax number on the e-invoice page). Every other buyer detail stays as LHDN received it. `SubmitFolioEInvoice`
 * does the sending and its own checks, and the tax number goes to the guest profile once LHDN accepts it. A City ledger e-invoice takes its buyer from the company record, so it is
 * refused here (`not_resubmittable`). See docs/30-einvoice/01-lhdn-myinvois.md#checking-a-tax-number.
 */
class ResubmitWithTaxNumber
{
    public const string REFUSAL_NOT_RESUBMITTABLE = 'not_resubmittable';

    public function __construct(private SubmitFolioEInvoice $submitFolioEInvoice) {}

    public function __invoke(EInvoiceSubmission $rejected, User $actor, string $tin): EInvoiceResult
    {
        if ($rejected->status !== EInvoiceStatus::Invalid
            || $rejected->document_type !== EInvoiceDocumentType::Invoice
            || $rejected->is_consolidated
            || $rejected->ar_invoice_id !== null
            || $rejected->folio === null) {
            return EInvoiceResult::refused(self::REFUSAL_NOT_RESUBMITTABLE, __('Only a rejected stay e-invoice can be sent again from here.'));
        }

        $buyer = [...($rejected->buyer_snapshot ?? []), 'tin' => strtoupper(trim($tin))];

        return ($this->submitFolioEInvoice)($rejected->folio, $actor, (bool) $rejected->requested_by_guest, $buyer, saveToGuest: true);
    }
}

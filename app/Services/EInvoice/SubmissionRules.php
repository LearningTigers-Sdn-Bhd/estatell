<?php

namespace App\Services\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceEnvironment;
use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * What staff may do with an e-invoice document and what it is worth, read from the stored document: the 72-hour
 * cancel window, the MyInvois link, the total LHDN holds and what is left to credit. It reads only. See
 * docs/31-einvoice/01-lhdn-myinvois.md#document-rules.
 */
class SubmissionRules
{
    /** LHDN allows cancelling a document for 72 hours after it validated it (e-Invoice General FAQ). */
    public const int CANCELLATION_WINDOW_HOURS = 72;

    /**
     * The end of the cancel window, or null for a document LHDN has not validated.
     */
    public function cancellationDeadline(EInvoiceSubmission $submission): ?CarbonImmutable
    {
        return $submission->validated_at?->addHours(self::CANCELLATION_WINDOW_HOURS);
    }

    /**
     * Whether staff may cancel it now: LHDN validated it, the window is open, and no credit or refund note LHDN has
     * or is checking corrects it. Expects nothing loaded; it runs one query for the notes.
     */
    public function isCancellable(EInvoiceSubmission $submission): bool
    {
        $deadline = $this->cancellationDeadline($submission);

        if ($submission->status !== EInvoiceStatus::Valid || $submission->cancelled_at !== null || empty($submission->uuid) || $deadline === null || now()->isAfter($deadline)) {
            return false;
        }

        return ! $submission->adjustments()->active()->exists();
    }

    /**
     * The public MyInvois page of a validated document, or null before LHDN gives it a long id.
     */
    public function validationUrl(EInvoiceSubmission $submission): ?string
    {
        if (empty($submission->uuid) || empty($submission->long_id)) {
            return null;
        }

        $portalUrl = $submission->property->eInvoiceSetting?->api_environment?->portalUrl() ?? EInvoiceEnvironment::Sandbox->portalUrl();

        return "{$portalUrl}/{$submission->uuid}/share/{$submission->long_id}";
    }

    /**
     * The payable total of the document as it was sent, or null when the stored document has none.
     */
    public function total(EInvoiceSubmission $submission): ?BigDecimal
    {
        // A credit or refund note is sent under the Invoice root too, with its own type code.
        $amount = data_get($submission->document_payload, 'Invoice.0.LegalMonetaryTotal.0.PayableAmount.0._')
            ?? data_get($submission->document_payload, 'CreditNote.0.LegalMonetaryTotal.0.PayableAmount.0._');

        return $amount === null ? null : BigDecimal::of((string) $amount)->toScale(2);
    }

    /**
     * What credit and refund notes may still take off an e-invoice: its total less the notes LHDN has or is checking.
     * Null when the stored e-invoice has no total.
     */
    public function creditRemaining(EInvoiceSubmission $original): ?BigDecimal
    {
        $total = $this->total($original);
        if ($total === null) {
            return null;
        }

        $credited = $original->adjustments()
            ->whereIn('document_type', [EInvoiceDocumentType::CreditNote, EInvoiceDocumentType::RefundNote])
            ->active()
            ->get()
            ->reduce(fn (BigDecimal $sum, EInvoiceSubmission $note): BigDecimal => $sum->plus($this->total($note) ?? BigDecimal::zero()), BigDecimal::zero());

        return $total->minus($credited);
    }
}

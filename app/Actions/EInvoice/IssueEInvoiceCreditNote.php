<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use App\Models\User;
use App\Services\EInvoice\CreditNoteDocumentBuilder;
use App\Services\EInvoice\MissingEInvoiceDetails;
use App\Services\EInvoice\SubmissionRules;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class IssueEInvoiceCreditNote
{
    public function __construct(
        private SendEInvoiceToLhdn $sendEInvoiceToLhdn,
        private LockEInvoiceRecords $lockEInvoiceRecords,
        private SubmissionRules $submissionRules,
        private CreditNoteDocumentBuilder $creditNoteDocumentBuilder,
    ) {}

    /**
     * Issue a credit or refund note on an e-invoice LHDN accepted. What is left to credit is checked, and the Pending
     * note saved, under the property's e-invoice lock (`LockEInvoiceRecords`), so two notes cannot both take the same
     * remainder; the call to LHDN runs after that commits (`SendEInvoiceToLhdn`).
     */
    public function __invoke(
        EInvoiceSubmission $originalSubmission,
        string $creditNoteNumber,
        string $amount,
        string $reason,
        User $actor,
        EInvoiceDocumentType $type = EInvoiceDocumentType::CreditNote,
    ): EInvoiceResult {
        $property = $originalSubmission->property;
        $setting = $property->eInvoiceSetting;

        if ($setting === null || ! $setting->enabled) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_ENABLED, __('E-invoicing is not enabled for this property.'));
        }

        if ($originalSubmission->status !== EInvoiceStatus::Valid || empty($originalSubmission->uuid)) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_ORIGINAL_NOT_VALID, __('A credit note needs an e-invoice LHDN accepted.'));
        }

        if (trim($reason) === '') {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_REASON_REQUIRED, __('Enter a reason for the credit note.'));
        }

        $prepared = ($this->lockEInvoiceRecords)($property, function () use ($originalSubmission, $creditNoteNumber, $amount, $reason, $type): EInvoiceResult|array {
            // It may have been cancelled since the page loaded.
            if ($originalSubmission->refresh()->status !== EInvoiceStatus::Valid) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_ORIGINAL_NOT_VALID, __('A credit note needs an e-invoice LHDN accepted.'));
            }

            // Credit and refund notes LHDN has or is checking already take their share of the e-invoice.
            $remaining = $this->submissionRules->creditRemaining($originalSubmission);

            if ($remaining !== null && BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp)->isGreaterThan($remaining)) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_CREDIT_EXCEEDS_REMAINING, __('A :document can take off at most :amount, what is left on this e-invoice.', ['amount' => number_format(max($remaining->toFloat(), 0), 2), 'document' => $type === EInvoiceDocumentType::RefundNote ? __('refund note') : __('credit note')]));
            }

            try {
                $built = $this->creditNoteDocumentBuilder->build($originalSubmission, $creditNoteNumber, $amount, $reason, $type);
            } catch (MissingEInvoiceDetails $e) {
                return EInvoiceResult::refused($e->refusal, $e->getMessage());
            }

            $submission = EInvoiceSubmission::query()->create([
                'property_id' => $originalSubmission->property_id,
                'folio_id' => $originalSubmission->folio_id,
                'ar_invoice_id' => $originalSubmission->ar_invoice_id,
                'reservation_id' => $originalSubmission->reservation_id,
                'company_id' => $originalSubmission->company_id,
                'original_submission_id' => $originalSubmission->id,
                'document_type' => $type,
                'scenario' => $originalSubmission->scenario,
                'status' => EInvoiceStatus::Pending,
                'internal_id' => $creditNoteNumber,
                'buyer_snapshot' => $originalSubmission->buyer_snapshot,
                'document_payload' => $built['payload'],
                'is_consolidated' => false,
                'requested_by_guest' => $originalSubmission->requested_by_guest,
                'cancel_reason' => $reason,
            ]);

            return [$submission, $built];
        });

        if ($prepared instanceof EInvoiceResult) {
            return $prepared;
        }

        [$submission, $built] = $prepared;
        $submission = ($this->sendEInvoiceToLhdn)($submission, $built);

        activity()
            ->performedOn($submission)
            ->causedBy($actor)
            ->event('einvoice.credit_note_issued')
            ->withProperties([
                'original_submission_id' => $originalSubmission->id,
                'submission_id' => $submission->id,
                'internal_id' => $submission->internal_id,
                'uuid' => $submission->uuid,
                'reason' => $reason,
            ])
            ->log('Issued e-invoice credit note to LHDN MyInvois');

        return EInvoiceResult::done($submission);
    }
}

<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Jobs\RefreshEInvoiceStatusJob;
use App\Models\EInvoiceSubmission;
use App\Models\User;
use App\Services\EInvoice\CreditNoteDocumentBuilder;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IssueEInvoiceCreditNote
{
    public function __construct(
        private RefreshEInvoiceStatus $refreshEInvoiceStatus
    ) {}

    public function __invoke(
        EInvoiceSubmission $originalSubmission,
        string $creditNoteNumber,
        float $amount,
        string $reason,
        User $actor
    ): EInvoiceResult {
        $property = $originalSubmission->property;
        $setting = $property->eInvoiceSetting;

        if ($setting === null || ! $setting->enabled) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_ENABLED, __('E-invoicing is not enabled for this property.'));
        }

        if (! $originalSubmission->isValid() || empty($originalSubmission->uuid)) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_ORIGINAL_NOT_VALID, __('Cannot issue Credit Note: the original e-invoice is not in Valid status or is missing an LHDN UUID.'));
        }

        if (trim($reason) === '') {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_REASON_REQUIRED, __('A reason is required to issue a credit note.'));
        }

        try {
            $builder = new CreditNoteDocumentBuilder($originalSubmission, $creditNoteNumber, $amount, $reason);
            $built = $builder->build();
        } catch (\InvalidArgumentException $e) {
            $refusal = str_contains($e->getMessage(), 'Property')
                ? EInvoiceResult::REFUSAL_SUPPLIER_PROFILE_INCOMPLETE
                : EInvoiceResult::REFUSAL_BUYER_DETAILS_INCOMPLETE;

            return EInvoiceResult::refused($refusal, $e->getMessage());
        }

        return DB::transaction(function () use ($originalSubmission, $creditNoteNumber, $reason, $actor, $built, $setting): EInvoiceResult {
            $submission = new EInvoiceSubmission([
                'property_id' => $originalSubmission->property_id,
                'folio_id' => $originalSubmission->folio_id,
                'ar_invoice_id' => $originalSubmission->ar_invoice_id,
                'reservation_id' => $originalSubmission->reservation_id,
                'company_id' => $originalSubmission->company_id,
                'original_submission_id' => $originalSubmission->id,
                'document_type' => EInvoiceDocumentType::CreditNote,
                'scenario' => $originalSubmission->scenario,
                'status' => EInvoiceStatus::Pending,
                'internal_id' => $creditNoteNumber,
                'buyer_snapshot' => $originalSubmission->buyer_snapshot,
                'document_payload' => $built['payload'],
                'is_consolidated' => false,
                'requested_by_guest' => $originalSubmission->requested_by_guest,
                'cancel_reason' => $reason,
            ]);
            $submission->save();

            try {
                $client = MyInvoisClientFactory::forSetting($setting);
                $response = $client->submitDocuments([
                    [
                        'format' => $built['format'],
                        'document' => $built['document'],
                        'documentHash' => $built['documentHash'],
                        'codeNumber' => $built['codeNumber'],
                    ],
                ]);

                $submissionUid = $response['submissionUid'] ?? null;
                $accepted = $response['acceptedDocuments'][0] ?? null;
                $rejected = $response['rejectedDocuments'][0] ?? null;

                $submission->submission_uid = $submissionUid;
                $submission->raw_response = $response;
                $submission->submitted_at = CarbonImmutable::now();

                if ($accepted !== null) {
                    $submission->uuid = $accepted['uuid'] ?? null;
                    $submission->status = EInvoiceStatus::Submitted;
                    $submission->save();

                    $refreshed = ($this->refreshEInvoiceStatus)($submission);

                    // LHDN validates in the background, so keep checking until it answers.
                    if ($refreshed->status === EInvoiceStatus::Submitted) {
                        RefreshEInvoiceStatusJob::dispatch($submission->id)->afterCommit();
                    }
                } elseif ($rejected !== null) {
                    $submission->status = EInvoiceStatus::Invalid;
                    $submission->error_details = $rejected['error'] ?? $rejected;
                    $submission->save();
                } else {
                    $submission->status = EInvoiceStatus::Submitted;
                    $submission->save();
                }

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
            } catch (MyInvoisApiException $e) {
                Log::error("Failed to submit e-invoice credit note {$creditNoteNumber}: {$e->getMessage()}");

                $submission->status = EInvoiceStatus::Invalid;
                $submission->error_details = [
                    'message' => $e->getMessage(),
                    'code' => $e->statusCode,
                    'body' => $e->responseBody,
                ];
                $submission->save();

                return EInvoiceResult::done($submission);
            }
        });
    }
}

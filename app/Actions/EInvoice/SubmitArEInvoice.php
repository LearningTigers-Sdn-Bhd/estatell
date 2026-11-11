<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Jobs\RefreshEInvoiceStatusJob;
use App\Models\ArInvoice;
use App\Models\EInvoiceSubmission;
use App\Models\User;
use App\Services\EInvoice\ArInvoiceDocumentBuilder;
use App\Services\EInvoice\BuyerResolver;
use App\Services\EInvoice\FolioEInvoiceLock;
use App\Services\EInvoice\MissingEInvoiceDetails;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubmitArEInvoice
{
    public function __construct(
        private RefreshEInvoiceStatus $refreshEInvoiceStatus,
        private FolioEInvoiceLock $folioEInvoiceLock,
    ) {}

    public function __invoke(ArInvoice $invoice, User $actor): EInvoiceResult
    {
        $property = $invoice->property;
        $setting = $property->eInvoiceSetting;

        if ($setting === null || ! $setting->enabled) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_ENABLED, __('E-invoicing is not enabled for this property.'));
        }

        if (! $setting->isReadyToSubmit()) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_CREDENTIALS_MISSING, __('MyInvois credentials are not configured.'));
        }

        $existing = EInvoiceSubmission::query()
            ->where('ar_invoice_id', $invoice->id)
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->active()
            ->first();

        if ($existing !== null) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_ALREADY_EXISTS, __('This invoice already has an e-invoice.'));
        }

        // The stay may already be with LHDN: in a monthly summary from before it was billed, or as its own e-invoice.
        $reported = $invoice->folio === null ? null : $this->folioEInvoiceLock->for($invoice->folio);
        if ($reported !== null && $reported->ar_invoice_id !== $invoice->id) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_ALREADY_REPORTED, $reported->is_consolidated
                ? __('This stay is already in monthly summary :number, so LHDN has it. If the company needs its own e-invoice, cancel the summary within 72 hours and send both again.', ['number' => $reported->internal_id])
                : __('This stay already has e-invoice :number, so LHDN has it.', ['number' => $reported->internal_id]));
        }

        try {
            $builder = new ArInvoiceDocumentBuilder($invoice);
            $built = $builder->build();
        } catch (MissingEInvoiceDetails $e) {
            return EInvoiceResult::refused($e->refusal, $e->getMessage());
        }

        $buyer = BuyerResolver::forCompany($invoice->company, $property);

        return DB::transaction(function () use ($invoice, $actor, $buyer, $built, $setting): EInvoiceResult {
            $submission = new EInvoiceSubmission([
                'property_id' => $invoice->property_id,
                'ar_invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'folio_id' => $invoice->folio_id,
                'document_type' => EInvoiceDocumentType::Invoice,
                'scenario' => EInvoiceScenario::ArInvoice,
                'status' => EInvoiceStatus::Pending,
                'internal_id' => $built['codeNumber'],
                'buyer_snapshot' => $buyer,
                'document_payload' => $built['payload'],
                'is_consolidated' => false,
                'requested_by_guest' => false,
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

                    // LHDN often refuses a status read for the first seconds while it validates. The queued
                    // refresh below tries again, so an error here leaves the document Submitted.
                    try {
                        $refreshed = ($this->refreshEInvoiceStatus)($submission);
                    } catch (MyInvoisApiException) {
                        $refreshed = $submission;
                    }

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
                    ->performedOn($invoice)
                    ->causedBy($actor)
                    ->event('einvoice.submitted')
                    ->withProperties([
                        'submission_id' => $submission->id,
                        'internal_id' => $submission->internal_id,
                        'uuid' => $submission->uuid,
                        'status' => $submission->status->value,
                    ])
                    ->log('Submitted AR e-invoice to LHDN MyInvois');

                return EInvoiceResult::done($submission);
            } catch (MyInvoisApiException $e) {
                Log::error("Failed to submit AR e-invoice for invoice {$invoice->id}: {$e->getMessage()}");

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

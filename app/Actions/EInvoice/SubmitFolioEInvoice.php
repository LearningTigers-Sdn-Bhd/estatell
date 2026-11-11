<?php

namespace App\Actions\EInvoice;

use App\Enums\ArInvoiceStatus;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioStatus;
use App\Features\EInvoicing;
use App\Jobs\RefreshEInvoiceStatusJob;
use App\Models\ArInvoice;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\User;
use App\Services\EInvoice\ConsolidatedBatchBuilder;
use App\Services\EInvoice\FolioDocumentBuilder;
use App\Services\EInvoice\MissingEInvoiceDetails;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class SubmitFolioEInvoice
{
    public function __construct(
        private RefreshEInvoiceStatus $refreshEInvoiceStatus
    ) {}

    /**
     * @param  array<string, mixed>|null  $buyerSnapshot
     * @param  bool  $saveToGuest  Save the tax number and ID to the paying guest's profile once LHDN accepts it.
     */
    public function __invoke(Folio $folio, User $actor, bool $requestedByGuest = false, ?array $buyerSnapshot = null, bool $saveToGuest = false): EInvoiceResult
    {
        $property = $folio->property;
        $setting = $property->eInvoiceSetting;

        if ($setting === null || ! $setting->enabled || ! Feature::for($property)->active(EInvoicing::class)) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_ENABLED, __('E-invoicing is not enabled for this property.'));
        }

        if (! $setting->isReadyToSubmit()) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_CREDENTIALS_MISSING, __('MyInvois credentials are not configured.'));
        }

        if ($folio->status !== FolioStatus::Closed) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_FOLIO_NOT_CLOSED, __('Only closed folios can be submitted for e-invoicing.'));
        }

        $existing = EInvoiceSubmission::query()
            ->where('folio_id', $folio->id)
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->active()
            ->exists();

        if ($existing) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_ALREADY_EXISTS, __('This folio already has an e-invoice.'));
        }

        $inConsolidatedBatch = EInvoiceSubmission::query()
            ->active()
            ->whereHas('folios', fn ($query) => $query->whereKey($folio->id))
            ->exists();

        if ($inConsolidatedBatch) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_ALREADY_EXISTS, __('This stay is already in a monthly summary, so it cannot get its own e-invoice.'));
        }

        if (ConsolidatedBatchBuilder::calculateFolioGrossAmount($folio->loadMissing('lines.reversal')) <= 0) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOTHING_TO_REPORT, __('Every charge on this folio was reversed, so there is nothing to e-invoice.'));
        }

        $billedBy = ArInvoice::query()->where('folio_id', $folio->id)->where('status', '<>', ArInvoiceStatus::Void)->first();
        if ($billedBy !== null) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_BILLED_TO_CITY_LEDGER, __('This stay was billed to a company on city ledger invoice :number. Send the e-invoice from that invoice instead.', ['number' => $billedBy->number]));
        }

        try {
            $builder = new FolioDocumentBuilder($folio, $buyerSnapshot);
            $built = $builder->build();
        } catch (MissingEInvoiceDetails $e) {
            return EInvoiceResult::refused($e->refusal, $e->getMessage());
        }

        // Keep the buyer exactly as LHDN received it, so a credit note names the same buyer.
        $buyer = $built['buyer'];

        return DB::transaction(function () use ($folio, $actor, $requestedByGuest, $buyer, $built, $setting, $saveToGuest): EInvoiceResult {
            $submission = new EInvoiceSubmission([
                'property_id' => $folio->property_id,
                'folio_id' => $folio->id,
                'reservation_id' => $folio->reservation_id,
                'company_id' => $folio->payer_company_id,
                'document_type' => EInvoiceDocumentType::Invoice,
                'scenario' => EInvoiceScenario::Folio,
                'status' => EInvoiceStatus::Pending,
                'internal_id' => $built['codeNumber'],
                'buyer_snapshot' => $buyer,
                'document_payload' => $built['payload'],
                'is_consolidated' => false,
                'requested_by_guest' => $requestedByGuest,
                'save_buyer_to_guest' => $saveToGuest,
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
                    ->performedOn($folio)
                    ->causedBy($actor)
                    ->event('einvoice.submitted')
                    ->withProperties([
                        'submission_id' => $submission->id,
                        'internal_id' => $submission->internal_id,
                        'uuid' => $submission->uuid,
                        'status' => $submission->status->value,
                    ])
                    ->log('Submitted e-invoice to LHDN MyInvois');

                return EInvoiceResult::done($submission);
            } catch (MyInvoisApiException $e) {
                Log::error("Failed to submit e-invoice for folio {$folio->id}: {$e->getMessage()}");

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

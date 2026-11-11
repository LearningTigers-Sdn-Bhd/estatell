<?php

namespace App\Actions\EInvoice;

use App\Enums\ArInvoiceStatus;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioStatus;
use App\Jobs\RefreshEInvoiceStatusJob;
use App\Models\ArInvoice;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\Property;
use App\Models\User;
use App\Services\EInvoice\ConsolidatedBatchBuilder;
use App\Services\EInvoice\MalaysiaStates;
use App\Services\EInvoice\MissingEInvoiceDetails;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConsolidateMonthlyEInvoices
{
    public function __construct(
        private RefreshEInvoiceStatus $refreshEInvoiceStatus
    ) {}

    /**
     * @return list<EInvoiceSubmission>
     */
    public function __invoke(Property $property, CarbonImmutable $month, ?User $actor = null): array
    {
        $setting = $property->eInvoiceSetting;
        if ($setting === null || ! $setting->enabled || ! $setting->consolidation_enabled) {
            return [];
        }

        $monthStart = $month->startOfMonth();
        $monthEnd = $month->endOfMonth();

        // Find all closed folios in the month that were not individually submitted
        $individuallySubmittedFolioIds = EInvoiceSubmission::query()
            ->where('property_id', $property->id)
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->where('is_consolidated', false)
            ->active()
            ->whereNotNull('folio_id')
            ->pluck('folio_id')
            ->all();

        // Exclude folios that are already part of an active consolidated batch
        $alreadyConsolidatedFolioIds = DB::table('e_invoice_submission_folios')
            ->join('e_invoice_submissions', 'e_invoice_submissions.id', '=', 'e_invoice_submission_folios.submission_id')
            ->where('e_invoice_submissions.property_id', $property->id)
            ->whereNotIn('e_invoice_submissions.status', [EInvoiceStatus::Cancelled->value, EInvoiceStatus::Invalid->value])
            ->pluck('e_invoice_submission_folios.folio_id')
            ->all();

        $excludedFolioIds = array_unique([...$individuallySubmittedFolioIds, ...$alreadyConsolidatedFolioIds]);

        $candidateFolios = Folio::query()
            ->where('property_id', $property->id)
            ->where('status', FolioStatus::Closed)
            ->whereBetween('closed_at', [$monthStart, $monthEnd])
            ->whereNotIn('id', $excludedFolioIds)
            // A stay billed to a company is e-invoiced through its city ledger invoice, not the general public summary.
            ->whereNotIn('id', ArInvoice::query()->whereNotNull('folio_id')->where('status', '<>', ArInvoiceStatus::Void)->select('folio_id'))
            ->with(['lines.transactionCode', 'lines.reversal'])
            ->get();

        // Stays > RM 10,000 cannot be consolidated into monthly batches per LHDN rules.
        $eligibleFolios = $candidateFolios->filter(function (Folio $folio): bool {
            $gross = ConsolidatedBatchBuilder::calculateFolioGrossAmount($folio);

            return $gross > 0.0 && $gross <= ConsolidatedBatchBuilder::MAX_INDIVIDUAL_STAY_AMOUNT;
        })->values();

        if ($eligibleFolios->isEmpty()) {
            return [];
        }

        // Chunk into groups of at most 100 folios
        $chunks = $eligibleFolios->chunk(ConsolidatedBatchBuilder::MAX_FOLIOS_PER_DOCUMENT);
        $createdSubmissions = [];

        foreach ($chunks as $chunk) {
            $submission = $this->submitChunk($property, $chunk, $monthStart, $actor);
            if ($submission !== null) {
                $createdSubmissions[] = $submission;
            }
        }

        return $createdSubmissions;
    }

    /**
     * @param  Collection<int, Folio>  $chunk
     */
    private function submitChunk(Property $property, Collection $chunk, CarbonImmutable $month, ?User $actor): ?EInvoiceSubmission
    {
        $setting = $property->eInvoiceSetting;
        if ($setting === null) {
            return null;
        }

        try {
            $builder = new ConsolidatedBatchBuilder($property, $chunk, $month);
            $built = $builder->build();
        } catch (MissingEInvoiceDetails $e) {
            // Staff must see what to fill in, not "no stays to send".
            throw $e;
        } catch (\Throwable $e) {
            Log::error("Failed to build consolidated e-invoice batch: {$e->getMessage()}");

            return null;
        }

        return DB::transaction(function () use ($property, $built, $setting, $actor, $chunk): EInvoiceSubmission {
            $submission = new EInvoiceSubmission([
                'property_id' => $property->id,
                'document_type' => EInvoiceDocumentType::Invoice,
                'scenario' => EInvoiceScenario::Consolidation,
                'status' => EInvoiceStatus::Pending,
                'internal_id' => $built['codeNumber'],
                'buyer_snapshot' => [
                    'name' => ConsolidatedBatchBuilder::GENERAL_CONSUMER_NAME,
                    'tin' => ConsolidatedBatchBuilder::GENERAL_CONSUMER_TIN,
                    'id_type' => 'BRN',
                    'id_value' => 'NA',
                    'state_code' => MalaysiaStates::NOT_APPLICABLE,
                ],
                'document_payload' => $built['payload'],
                'is_consolidated' => true,
                'requested_by_guest' => false,
            ]);
            $submission->save();
            $submission->folios()->attach($chunk->pluck('id')->all());

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

                if ($actor !== null) {
                    activity()
                        ->performedOn($submission)
                        ->causedBy($actor)
                        ->event('einvoice.consolidated')
                        ->withProperties([
                            'submission_id' => $submission->id,
                            'internal_id' => $submission->internal_id,
                            'uuid' => $submission->uuid,
                        ])
                        ->log('Submitted monthly consolidated e-invoice');
                }

                return $submission;
            } catch (MyInvoisApiException $e) {
                Log::error("Failed to submit consolidated e-invoice {$built['codeNumber']}: {$e->getMessage()}");

                $submission->status = EInvoiceStatus::Invalid;
                $submission->error_details = [
                    'message' => $e->getMessage(),
                    'code' => $e->statusCode,
                    'body' => $e->responseBody,
                ];
                $submission->save();

                return $submission;
            }
        });
    }
}

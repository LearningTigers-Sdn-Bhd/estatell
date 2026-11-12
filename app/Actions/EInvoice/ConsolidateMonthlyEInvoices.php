<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioStatus;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\Property;
use App\Models\User;
use App\Services\EInvoice\ConsolidatedBatchBuilder;
use App\Services\EInvoice\FolioEInvoiceLock;
use App\Services\EInvoice\MalaysiaStates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ConsolidateMonthlyEInvoices
{
    public function __construct(
        private SendEInvoiceToLhdn $sendEInvoiceToLhdn,
        private LockEInvoiceRecords $lockEInvoiceRecords,
        private ConsolidatedBatchBuilder $consolidatedBatchBuilder,
        private FolioEInvoiceLock $folioEInvoiceLock,
    ) {}

    /**
     * Send the month's closed stays that have no e-invoice of their own as monthly summaries of at most 100 stays.
     * Choosing the stays, building every part and saving their Pending records run in one transaction under the
     * property's e-invoice lock (`LockEInvoiceRecords`): if any part cannot be built, nothing is saved and the error
     * reaches the caller (`MissingEInvoiceDetails` for a gap in the hotel's details), so no stay is left out quietly.
     * Each part is then sent on its own (`SendEInvoiceToLhdn`).
     *
     * @return list<EInvoiceSubmission>
     */
    public function __invoke(Property $property, CarbonImmutable $month, ?User $actor = null): array
    {
        $setting = $property->eInvoiceSetting;
        if ($setting === null || ! $setting->enabled || ! $setting->consolidation_enabled) {
            return [];
        }

        $prepared = ($this->lockEInvoiceRecords)($property, function () use ($property, $month): array {
            $parts = [];

            foreach ($this->eligibleFolios($property, $month)->chunk(ConsolidatedBatchBuilder::MAX_FOLIOS_PER_DOCUMENT) as $chunk) {
                $built = $this->consolidatedBatchBuilder->build($property, $chunk->values(), $month->startOfMonth());

                $submission = EInvoiceSubmission::query()->create([
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
                // Each stay with the document line that holds it, so the summary page reads its amounts from that line.
                $submission->folios()->attach(collect($built['lines'])->map(fn (int $line): array => ['line_number' => $line])->all());

                $parts[] = [$submission, $built];
            }

            return $parts;
        });

        $submissions = [];

        foreach ($prepared as [$submission, $built]) {
            $submission = ($this->sendEInvoiceToLhdn)($submission, $built);

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

            $submissions[] = $submission;
        }

        return $submissions;
    }

    /**
     * The month's closed stays over RM0 and at most RM10,000 that are not on an e-invoice of their own, not in a
     * monthly summary LHDN has or is checking, and not billed to a company (a City ledger invoice e-invoices those).
     *
     * @return Collection<int, Folio>
     */
    private function eligibleFolios(Property $property, CarbonImmutable $month): Collection
    {
        $candidateFolios = $this->folioEInvoiceLock->whereStillToReport(Folio::query(), $property->id)
            ->where('property_id', $property->id)
            ->where('status', FolioStatus::Closed)
            ->whereBetween('closed_at', [$month->startOfMonth(), $month->endOfMonth()])
            ->with('lines.transactionCode')
            ->get();

        // A stay over RM10,000 needs its own e-invoice: LHDN does not take it in a summary.
        $eligibleFolios = $candidateFolios->filter(fn (Folio $folio): bool => $this->consolidatedBatchBuilder->fitsInSummary($folio))->values();

        return $eligibleFolios;
    }
}

<?php

namespace App\Actions\EInvoice;

use App\Enums\ArInvoiceStatus;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioStatus;
use App\Features\EInvoicing;
use App\Models\ArInvoice;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\User;
use App\Services\EInvoice\FolioDocumentBuilder;
use App\Services\EInvoice\FolioEInvoiceLock;
use App\Services\EInvoice\MissingEInvoiceDetails;
use App\Services\EInvoice\MyInvoisClientFactory;
use App\Services\EInvoice\ReportableLines;
use Laravel\Pennant\Feature;

class SubmitFolioEInvoice
{
    public function __construct(
        private SendEInvoiceToLhdn $sendEInvoiceToLhdn,
        private LockEInvoiceRecords $lockEInvoiceRecords,
        private FolioDocumentBuilder $folioDocumentBuilder,
        private ReportableLines $reportableLines,
        private FolioEInvoiceLock $folioEInvoiceLock,
    ) {}

    /**
     * Send a Closed folio's e-invoice. Every check and the Pending record run under the property's e-invoice lock
     * (`LockEInvoiceRecords`), so two clicks, or a click and the monthly summary, cannot both take the stay. The call to
     * LHDN runs after that commits (`SendEInvoiceToLhdn`). `$actor` is null when it is sent on its own at check-out.
     *
     * @param  array<string, mixed>|null  $buyerSnapshot
     * @param  bool  $saveToGuest  Save the tax number and ID to the paying guest's profile once LHDN accepts it.
     */
    public function __invoke(Folio $folio, ?User $actor, bool $requestedByGuest = false, ?array $buyerSnapshot = null, bool $saveToGuest = false): EInvoiceResult
    {
        $property = $folio->property;
        $setting = $property->eInvoiceSetting;

        if ($setting === null || ! $setting->enabled || ! Feature::for($property)->active(EInvoicing::class)) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_ENABLED, __('E-invoicing is not enabled for this property.'));
        }

        if (! MyInvoisClientFactory::isReady($setting)) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_CREDENTIALS_MISSING, __('MyInvois credentials are not configured.'));
        }

        $prepared = ($this->lockEInvoiceRecords)($property, function () use ($folio, $requestedByGuest, $buyerSnapshot, $saveToGuest): EInvoiceResult|array {
            $folio->refresh();

            if ($folio->status !== FolioStatus::Closed) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_FOLIO_NOT_CLOSED, __('Only closed folios can be submitted for e-invoicing.'));
            }

            $reported = $this->folioEInvoiceLock->for($folio);

            if ($reported !== null) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_ALREADY_EXISTS, $reported->is_consolidated
                    ? __('This stay is already in a monthly summary, so it cannot get its own e-invoice.')
                    : __('This folio already has an e-invoice.'));
            }

            if (! $this->reportableLines->gross($folio)->isPositive()) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOTHING_TO_REPORT, __('Every charge on this folio was reversed, so there is nothing to e-invoice.'));
            }

            $billedBy = ArInvoice::query()->where('folio_id', $folio->id)->where('status', '<>', ArInvoiceStatus::Void)->first();
            if ($billedBy !== null) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_BILLED_TO_CITY_LEDGER, __('This stay was billed to a company on city ledger invoice :number. Send the e-invoice from that invoice instead.', ['number' => $billedBy->number]));
            }

            try {
                $built = $this->folioDocumentBuilder->build($folio, $buyerSnapshot);
            } catch (MissingEInvoiceDetails $e) {
                return EInvoiceResult::refused($e->refusal, $e->getMessage());
            }

            // Keep the buyer exactly as LHDN received it, so a credit note names the same buyer.
            $submission = EInvoiceSubmission::query()->create([
                'property_id' => $folio->property_id,
                'folio_id' => $folio->id,
                'reservation_id' => $folio->reservation_id,
                'company_id' => $folio->payer_company_id,
                'document_type' => EInvoiceDocumentType::Invoice,
                'scenario' => EInvoiceScenario::Folio,
                'status' => EInvoiceStatus::Pending,
                'internal_id' => $built['codeNumber'],
                'buyer_snapshot' => $built['buyer'],
                'document_payload' => $built['payload'],
                'is_consolidated' => false,
                'requested_by_guest' => $requestedByGuest,
                'save_buyer_to_guest' => $saveToGuest,
            ]);

            return [$submission, $built];
        });

        if ($prepared instanceof EInvoiceResult) {
            return $prepared;
        }

        [$submission, $built] = $prepared;
        $submission = ($this->sendEInvoiceToLhdn)($submission, $built);

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
    }
}

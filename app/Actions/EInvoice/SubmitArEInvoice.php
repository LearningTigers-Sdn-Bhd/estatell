<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Models\ArInvoice;
use App\Models\EInvoiceSubmission;
use App\Models\User;
use App\Services\EInvoice\ArInvoiceDocumentBuilder;
use App\Services\EInvoice\BuyerResolver;
use App\Services\EInvoice\FolioEInvoiceLock;
use App\Services\EInvoice\MissingEInvoiceDetails;
use App\Services\EInvoice\MyInvoisClientFactory;

class SubmitArEInvoice
{
    public function __construct(
        private SendEInvoiceToLhdn $sendEInvoiceToLhdn,
        private LockEInvoiceRecords $lockEInvoiceRecords,
        private FolioEInvoiceLock $folioEInvoiceLock,
        private ArInvoiceDocumentBuilder $arInvoiceDocumentBuilder,
    ) {}

    /**
     * Send a City ledger invoice's e-invoice. The checks and the Pending record run under the property's e-invoice
     * lock (`LockEInvoiceRecords`); the call to LHDN runs after that commits (`SendEInvoiceToLhdn`).
     */
    public function __invoke(ArInvoice $invoice, User $actor): EInvoiceResult
    {
        $property = $invoice->property;
        $setting = $property->eInvoiceSetting;

        if ($setting === null || ! $setting->enabled) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_ENABLED, __('E-invoicing is not enabled for this property.'));
        }

        if (! MyInvoisClientFactory::isReady($setting)) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_CREDENTIALS_MISSING, __('MyInvois credentials are not configured.'));
        }

        $prepared = ($this->lockEInvoiceRecords)($property, function () use ($invoice, $property): EInvoiceResult|array {
            $existing = EInvoiceSubmission::query()
                ->where('ar_invoice_id', $invoice->id)
                ->where('document_type', EInvoiceDocumentType::Invoice)
                ->active()
                ->exists();

            if ($existing) {
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
                $built = $this->arInvoiceDocumentBuilder->build($invoice);
            } catch (MissingEInvoiceDetails $e) {
                return EInvoiceResult::refused($e->refusal, $e->getMessage());
            }

            $submission = EInvoiceSubmission::query()->create([
                'property_id' => $invoice->property_id,
                'ar_invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'folio_id' => $invoice->folio_id,
                'document_type' => EInvoiceDocumentType::Invoice,
                'scenario' => EInvoiceScenario::ArInvoice,
                'status' => EInvoiceStatus::Pending,
                'internal_id' => $built['codeNumber'],
                'buyer_snapshot' => BuyerResolver::forCompany($invoice->company, $property),
                'document_payload' => $built['payload'],
                'is_consolidated' => false,
                'requested_by_guest' => false,
            ]);

            return [$submission, $built];
        });

        if ($prepared instanceof EInvoiceResult) {
            return $prepared;
        }

        [$submission, $built] = $prepared;
        $submission = ($this->sendEInvoiceToLhdn)($submission, $built);

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
    }
}

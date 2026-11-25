<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Features\EInvoicing;
use App\Models\EInvoiceSubmission;
use App\Models\PosOrder;
use App\Models\User;
use App\Services\EInvoice\MissingEInvoiceDetails;
use App\Services\EInvoice\MyInvoisClientFactory;
use App\Services\EInvoice\PosOrderDocumentBuilder;
use App\Services\EInvoice\PosOrderReportLines;
use Laravel\Pennant\Feature;

class SubmitPosWalkInEInvoice
{
    public const string REFUSAL_NOT_A_SALE = 'pos_not_a_sale';

    public function __construct(
        private SendEInvoiceToLhdn $sendEInvoiceToLhdn,
        private LockEInvoiceRecords $lockEInvoiceRecords,
        private PosOrderDocumentBuilder $posOrderDocumentBuilder,
        private PosOrderReportLines $posOrderReportLines,
    ) {}

    /**
     * Send the walk-in share of one settled POS sale as an e-invoice to a named buyer (a bill over RM10,000, or a diner
     * who asks at the counter). Every check and the Pending record run under the property's e-invoice lock
     * (`LockEInvoiceRecords`), so a click and the monthly summary cannot both take the order. A refund is not sent here. A guest
     * claiming their own receipt has no staff `$actor` and sets `$requestedByGuest`.
     *
     * @param  array<string, mixed>  $buyerSnapshot
     */
    public function __invoke(PosOrder $order, ?User $actor, array $buyerSnapshot, bool $requestedByGuest = false): EInvoiceResult
    {
        $property = $order->property;
        $setting = $property->eInvoiceSetting;

        if ($setting === null || ! $setting->enabled || ! Feature::for($property)->active(EInvoicing::class)) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_ENABLED, __('E-invoicing is not enabled for this property.'));
        }

        if (! MyInvoisClientFactory::isReady($setting)) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_CREDENTIALS_MISSING, __('MyInvois credentials are not configured.'));
        }

        $prepared = ($this->lockEInvoiceRecords)($property, function () use ($order, $buyerSnapshot, $requestedByGuest): EInvoiceResult|array {
            $order = PosOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->e_invoice_submission_id !== null) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_ALREADY_REPORTED, __('This sale is already on an e-invoice.'));
            }

            if ($order->order_type->value !== 'sale') {
                return EInvoiceResult::refused(self::REFUSAL_NOT_A_SALE, __('A refund cannot get its own e-invoice.'));
            }

            if (! $this->posOrderReportLines->reportable($order)) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOTHING_TO_REPORT, __('Nothing on this sale is left to e-invoice: it is not settled, or it was all charged to a room.'));
            }

            try {
                $built = $this->posOrderDocumentBuilder->build($order, $buyerSnapshot);
            } catch (MissingEInvoiceDetails $e) {
                return EInvoiceResult::refused($e->refusal, $e->getMessage());
            }

            $submission = EInvoiceSubmission::query()->create([
                'property_id' => $order->property_id,
                'document_type' => EInvoiceDocumentType::Invoice,
                'scenario' => EInvoiceScenario::PosOrder,
                'status' => EInvoiceStatus::Pending,
                'internal_id' => $built['codeNumber'],
                'buyer_snapshot' => $built['buyer'],
                'document_payload' => $built['payload'],
                'is_consolidated' => false,
                'requested_by_guest' => $requestedByGuest,
            ]);
            $submission->posOrders()->attach($order->id, ['line_number' => 1, 'is_active' => true]);
            $order->forceFill(['e_invoice_submission_id' => $submission->id])->save();

            return [$submission, $built];
        });

        if ($prepared instanceof EInvoiceResult) {
            return $prepared;
        }

        [$submission, $built] = $prepared;
        $submission = ($this->sendEInvoiceToLhdn)($submission, $built);

        activity()
            ->performedOn($order)
            ->causedBy($actor)
            ->event('einvoice.submitted')
            ->withProperties(['submission_id' => $submission->id, 'internal_id' => $submission->internal_id, 'uuid' => $submission->uuid, 'status' => $submission->status->value])
            ->log('Submitted POS walk-in e-invoice to LHDN MyInvois');

        return EInvoiceResult::done($submission);
    }
}

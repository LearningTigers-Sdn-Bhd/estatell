<?php

namespace App\Services\EInvoice;

use App\Data\Shared\BadgeData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Enums\PermissionEnum;
use App\Features\EInvoicing;
use App\Models\ArInvoice;
use App\Models\EInvoiceSubmission;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * The e-invoice part of a City ledger invoice page: the e-invoice LHDN has for it, and whether staff can send one. A
 * stay billed to a company gets its e-invoice here, not on the folio. Null when the property does not e-invoice.
 */
class ArInvoiceEInvoice
{
    public function __construct(private FolioEInvoiceLock $folioEInvoiceLock, private SubmissionStatusBadge $statusBadge) {}

    /**
     * @return array{submission: array{id: int, number: string, status: BadgeData}|null, reported_in: array{id: int, number: string, summary: bool}|null, can_send: bool}|null
     */
    public function for(ArInvoice $invoice, User $user): ?array
    {
        $property = $invoice->property;
        $setting = $property->eInvoiceSetting;

        if ($setting === null || ! $setting->enabled || ! Feature::for($property)->active(EInvoicing::class)
            || $invoice->type !== ArInvoiceType::Invoice || $invoice->migrated) {
            return null;
        }

        $latest = EInvoiceSubmission::query()
            ->where('ar_invoice_id', $invoice->id)
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->latest('id')
            ->first();
        $active = $latest !== null && ! in_array($latest->status, [EInvoiceStatus::Cancelled, EInvoiceStatus::Invalid], true);
        // Already with LHDN through the folio: a monthly summary from before it was billed, or the stay's own e-invoice.
        $hold = $invoice->folio === null ? null : $this->folioEInvoiceLock->for($invoice->folio);
        $reportedIn = $hold !== null && $hold->ar_invoice_id !== $invoice->id ? $hold : null;

        return [
            'submission' => $latest === null ? null : [
                'id' => $latest->id,
                'number' => $latest->internal_id,
                'status' => $this->statusBadge->for($latest),
            ],
            'reported_in' => $reportedIn === null ? null : ['id' => $reportedIn->id, 'number' => $reportedIn->internal_id, 'summary' => $reportedIn->is_consolidated],
            'can_send' => ! $active && $reportedIn === null && $invoice->status !== ArInvoiceStatus::Void && $user->can(PermissionEnum::EInvoiceManage->value),
        ];
    }
}

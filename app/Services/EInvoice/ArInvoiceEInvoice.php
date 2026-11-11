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
    /**
     * @return array{submission: array{id: int, number: string, status: BadgeData}|null, can_send: bool}|null
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

        return [
            'submission' => $latest === null ? null : [
                'id' => $latest->id,
                'number' => $latest->internal_id,
                'status' => new BadgeData($latest->status->value, $latest->status->label(), $latest->status->tone()),
            ],
            'can_send' => ! $active && $invoice->status !== ArInvoiceStatus::Void && $user->can(PermissionEnum::EInvoiceManage->value),
        ];
    }
}

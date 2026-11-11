<?php

namespace App\Actions\EInvoice;

use App\Actions\Guests\SetGuestTaxDetails;
use App\Enums\EInvoiceStatus;
use App\Enums\IdentityDocumentType;
use App\Models\EInvoiceSubmission;

/**
 * Once LHDN accepts a stay e-invoice that staff asked to remember ("Save to the guest profile"), saves its tax number
 * and IC or passport to the paying guest's profile, then clears the request. Waiting for Valid matters: a tax number
 * that does not match the IC comes back rejected minutes after sending. See docs/30-einvoice/01-lhdn-myinvois.md.
 */
class SaveBuyerToGuest
{
    public function __construct(private SetGuestTaxDetails $setGuestTaxDetails) {}

    public function __invoke(EInvoiceSubmission $submission): void
    {
        if (! $submission->save_buyer_to_guest || $submission->status !== EInvoiceStatus::Valid) {
            return;
        }

        $guest = $submission->folio?->payerGuest;
        $buyer = $submission->buyer_snapshot ?? [];
        $idType = match (strtoupper((string) ($buyer['id_type'] ?? ''))) {
            'NRIC' => IdentityDocumentType::MyKad,
            'PASSPORT' => IdentityDocumentType::Passport,
            default => null,
        };

        if ($guest !== null && $submission->folio->payer_company_id === null) {
            ($this->setGuestTaxDetails)($guest, (string) ($buyer['tin'] ?? ''), $idType, (string) ($buyer['id_value'] ?? ''));
        }

        $submission->forceFill(['save_buyer_to_guest' => false])->save();
    }
}

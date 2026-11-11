<?php

namespace App\Data\Payments;

use App\Data\Shared\BadgeData;
use App\Enums\PaymentProofStatus;
use App\Models\PaymentProof;
use Spatie\LaravelData\Data;

/**
 * A payment proof an agent sent, for the reservation page and the portal booking. `fileUrl` opens the file. `paidInto` is
 * the bank account the company says it paid into ("Maybank · 5123 4567 8901"); load `bankAccount` with the proof.
 * `invoiceNumber` is the invoice a company slip is for (null for the account or a booking proof); load `arInvoice`.
 */
class PaymentProofData extends Data
{
    public function __construct(
        public int $id,
        public string $amount,
        public ?string $reference,
        public ?string $paidInto,
        public PaymentProofStatus $status,
        public BadgeData $badge,
        public string $uploadedAt,
        public string $uploadedBy,
        public ?string $rejectReason,
        public string $fileUrl,
        public ?string $invoiceNumber = null,
    ) {}

    public static function fromModel(PaymentProof $proof, string $fileUrl): self
    {
        return new self(
            id: $proof->id,
            amount: $proof->amount,
            reference: $proof->reference,
            paidInto: $proof->bankAccount === null ? null : $proof->bankAccount->bank_name.' · '.$proof->bankAccount->account_number,
            status: $proof->status,
            badge: new BadgeData($proof->status->value, $proof->status->label(), $proof->status->tone()),
            uploadedAt: $proof->created_at?->toIso8601String() ?? '',
            uploadedBy: $proof->uploader->name ?? '',
            rejectReason: $proof->reject_reason,
            fileUrl: $fileUrl,
            invoiceNumber: $proof->ar_invoice_id === null ? null : $proof->arInvoice?->number,
        );
    }
}

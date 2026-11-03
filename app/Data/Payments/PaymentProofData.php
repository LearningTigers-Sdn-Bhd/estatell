<?php

namespace App\Data\Payments;

use App\Data\Shared\BadgeData;
use App\Enums\PaymentProofStatus;
use App\Models\PaymentProof;
use Spatie\LaravelData\Data;

/** A payment proof an agent sent, for the reservation page and the portal booking. `fileUrl` opens the file. */
class PaymentProofData extends Data
{
    public function __construct(
        public int $id,
        public string $amount,
        public ?string $reference,
        public PaymentProofStatus $status,
        public BadgeData $badge,
        public string $uploadedAt,
        public string $uploadedBy,
        public ?string $rejectReason,
        public string $fileUrl,
    ) {}

    public static function fromModel(PaymentProof $proof, string $fileUrl): self
    {
        return new self(
            id: $proof->id,
            amount: $proof->amount,
            reference: $proof->reference,
            status: $proof->status,
            badge: new BadgeData($proof->status->value, $proof->status->label(), $proof->status->tone()),
            uploadedAt: $proof->created_at?->toIso8601String() ?? '',
            uploadedBy: $proof->uploader->name ?? '',
            rejectReason: $proof->reject_reason,
            fileUrl: $fileUrl,
        );
    }
}

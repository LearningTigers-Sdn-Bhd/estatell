<?php

namespace App\Data\Pos;

use App\Data\Shared\BadgeData;
use App\Enums\Tone;
use App\Models\PosPosting;
use App\Services\Pos\PosRefusalMessages;
use Spatie\LaravelData\Data;

/**
 * One POS receipt row on the postings log. `reference` is the reservation of the room it was posted to, if any.
 * `failureReason` is a sentence for staff.
 */
class PosPostingRowData extends Data
{
    public function __construct(
        public int $id,
        public string $outletName,
        public string $receiptNumber,
        public string $type,
        public BadgeData $status,
        public string $amount,
        public ?string $failureReason,
        public ?string $reference,
        public string $createdAt,
    ) {}

    public static function fromModel(PosPosting $posting): self
    {
        return new self(
            $posting->id,
            $posting->outlet->name,
            $posting->external_receipt_number,
            $posting->posting_type->label(),
            new BadgeData($posting->status->value, $posting->status->label(), Tone::from($posting->status->tone())),
            $posting->amount,
            $posting->failure_reason === null ? null : PosRefusalMessages::message($posting->failure_reason),
            $posting->reservationUnit?->reservation->reference,
            $posting->created_at->toIso8601String(),
        );
    }
}

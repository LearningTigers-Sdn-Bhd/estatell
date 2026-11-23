<?php

namespace App\Data\Pos;

use App\Enums\PosPostingStatus;
use App\Models\PosPosting;
use Spatie\LaravelData\Data;

/**
 * One POS receipt with what the guest ordered (`rawPayload`, the provider's own receipt) and how it was settled.
 */
class PosPostingDetailData extends Data
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public PosPostingRowData $row,
        public array $rawPayload,
        public ?string $folioLine,
        public ?string $resolutionNote,
        public ?string $resolvedBy,
        public ?string $resolvedAt,
        public bool $canAttach,
    ) {}

    public static function fromModel(PosPosting $posting): self
    {
        return new self(
            PosPostingRowData::fromModel($posting),
            $posting->raw_payload,
            $posting->folioLine?->description,
            $posting->resolution_note,
            $posting->resolvedBy?->name,
            $posting->resolved_at?->toIso8601String(),
            $posting->status === PosPostingStatus::PendingReview && $posting->posting_type->value === 'charge',
        );
    }
}

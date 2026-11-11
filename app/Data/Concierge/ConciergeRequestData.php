<?php

namespace App\Data\Concierge;

use App\Data\Shared\BadgeData;
use App\Enums\GuestRequestStatus;
use App\Models\GuestRequest;
use Spatie\LaravelData\Data;

/**
 * One of the guest's own requests, as the Concierge page shows it. A cancel note is staff's own, so only a resolved
 * request shows its note.
 */
class ConciergeRequestData extends Data
{
    public function __construct(
        public int $id,
        public string $type,
        public string $details,
        public BadgeData $status,
        public string $openedAt,
        public ?string $note,
    ) {}

    public static function fromModel(GuestRequest $request): self
    {
        return new self(
            $request->id,
            $request->type->label(),
            $request->details,
            new BadgeData($request->status->value, $request->status->label(), $request->status->tone()),
            $request->opened_at->toIso8601String(),
            $request->status === GuestRequestStatus::Resolved ? $request->resolution_note : null,
        );
    }
}

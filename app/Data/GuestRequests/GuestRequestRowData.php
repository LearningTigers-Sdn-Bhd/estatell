<?php

namespace App\Data\GuestRequests;

use App\Data\Shared\BadgeData;
use App\Models\GuestRequest;
use Spatie\LaravelData\Data;

/**
 * One request as the list shows it. `moves` is what the viewer may do next (`start`, `resolve`, `cancel`, `reopen`), decided
 * on the server from the status and the viewer's permissions.
 */
class GuestRequestRowData extends Data
{
    /**
     * @param  list<string>  $moves
     */
    public function __construct(
        public int $id,
        public BadgeData $type,
        public string $source,
        public BadgeData $status,
        public string $details,
        public ?string $roomNumber,
        public ?string $guestName,
        public ?int $assignedTo,
        public ?string $assigneeName,
        public string $openedAt,
        public ?string $resolvedAt,
        public ?string $resolutionNote,
        public array $moves,
    ) {}

    /**
     * @param  list<string>  $moves
     */
    public static function fromModel(GuestRequest $request, array $moves): self
    {
        return new self(
            id: $request->id,
            type: new BadgeData($request->type->value, $request->type->label(), $request->type->tone()),
            source: $request->source->label(),
            status: new BadgeData($request->status->value, $request->status->label(), $request->status->tone()),
            details: $request->details,
            roomNumber: $request->room?->number,
            guestName: $request->stay?->reservation?->booker?->full_name,
            assignedTo: $request->assigned_to,
            assigneeName: $request->assignee?->name,
            openedAt: $request->opened_at->toIso8601String(),
            resolvedAt: $request->resolved_at?->toIso8601String(),
            resolutionNote: $request->resolution_note,
            moves: $moves,
        );
    }
}

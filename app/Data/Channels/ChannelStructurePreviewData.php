<?php

namespace App\Data\Channels;

use App\Services\Channels\ChannelStructure;
use App\Services\Channels\ChannelStructurePair;
use Spatie\LaravelData\Data;

/**
 * What Sync to Channex would send, for the preview Dialog.
 */
class ChannelStructurePreviewData extends Data
{
    /**
     * @param  list<ChannelStructureRowData>  $rows
     */
    public function __construct(
        public array $rows,
        public int $roomTypes,
        public int $ratePlans,
        public int $skipped,
        public ?string $blockedReason,
    ) {}

    public static function fromStructure(ChannelStructure $structure): self
    {
        return new self(
            rows: array_map(fn (ChannelStructurePair $pair): ChannelStructureRowData => ChannelStructureRowData::fromPair($pair), $structure->pairs),
            roomTypes: $structure->roomTypeCount(),
            ratePlans: $structure->ratePlanCount(),
            skipped: $structure->skippedCount(),
            blockedReason: $structure->blockedReason,
        );
    }
}

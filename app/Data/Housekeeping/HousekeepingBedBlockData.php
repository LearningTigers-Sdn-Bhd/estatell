<?php

namespace App\Data\Housekeeping;

use App\Models\RoomBlock;
use Spatie\LaravelData\Data;

/**
 * An open block on one bed of a room. The room stays workable, so the board only shows a note.
 */
class HousekeepingBedBlockData extends Data
{
    public function __construct(
        public string $bedLabel,
        public string $reasonLabel,
        public string $endsOn,
    ) {}

    /**
     * Expects `bed` loaded.
     */
    public static function fromModel(RoomBlock $block): self
    {
        return new self($block->bed->label, $block->reason->label(), $block->ends_on->toDateString());
    }
}

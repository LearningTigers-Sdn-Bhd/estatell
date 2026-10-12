<?php

namespace App\Data\Housekeeping;

use App\Models\RoomBlock;
use Spatie\LaravelData\Data;

/**
 * The open whole-room block that takes a room out of service on the board date.
 */
class HousekeepingOutOfServiceData extends Data
{
    public function __construct(
        public string $reasonLabel,
        public string $endsOn,
        public ?string $notes,
    ) {}

    public static function fromModel(RoomBlock $block): self
    {
        return new self($block->reason->label(), $block->ends_on->toDateString(), $block->notes);
    }
}

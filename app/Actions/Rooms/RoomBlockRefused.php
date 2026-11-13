<?php

namespace App\Actions\Rooms;

use App\Enums\RoomBlockRefusal;
use App\Models\RoomBlock;
use App\Models\UnitNight;
use Carbon\CarbonInterface;

/**
 * Why `CreateRoomBlock` or `UpdateRoomBlock` saved nothing, with what it clashed with: the dates of the other block,
 * or the first night of the stay and its booking reference.
 */
class RoomBlockRefused
{
    private function __construct(
        public readonly RoomBlockRefusal $refusal,
        public readonly ?CarbonInterface $from = null,
        public readonly ?CarbonInterface $to = null,
        public readonly ?string $reference = null,
    ) {}

    public static function because(RoomBlockRefusal $refusal): self
    {
        return new self($refusal);
    }

    public static function blockedBy(RoomBlock $block): self
    {
        return new self(RoomBlockRefusal::Blocked, $block->starts_on, $block->ends_on);
    }

    public static function stayOn(UnitNight $night): self
    {
        return new self(RoomBlockRefusal::HasStay, $night->date, reference: $night->reservationUnit?->reservation?->reference);
    }

    /**
     * @param  string  $unit  "Room 203" or "Room 12, bed A3".
     */
    public function message(string $unit): string
    {
        return $this->refusal->message($unit, $this->from, $this->to, $this->reference);
    }
}

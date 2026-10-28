<?php

namespace App\Data\Reservations;

use App\Enums\SellUnit;
use App\Models\RoomType;
use Spatie\LaravelData\Data;

/**
 * A room type to pick in the Add unit Sheet. `enteredPlanId` is the per-night plan a day use with an entered price is stored on (null when the
 * room type has none, which turns **No plan · enter price** off).
 */
class RoomTypeOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isBed,
        public int $maxAdults,
        public int $maxChildren,
        public ?int $enteredPlanId = null,
    ) {}

    public static function fromModel(RoomType $roomType, ?int $enteredPlanId = null): self
    {
        return new self($roomType->id, $roomType->name, $roomType->sell_unit === SellUnit::Bed, $roomType->max_adults, $roomType->max_children, $enteredPlanId);
    }
}

<?php

namespace App\Data\Onboarding;

use App\Enums\SellUnit;
use App\Models\RoomType;
use Spatie\LaravelData\Data;

/**
 * One tile of the room type picker in the onboarding rooms step. `bedsPerRoom` starts the Beds per room input of a
 * bed-sold type: the bed count of its first saved room with beds, or 4. Beds have no stored capacity.
 */
class OnboardingRoomTypeTileData extends Data
{
    public const DEFAULT_BEDS_PER_ROOM = 4;

    public function __construct(
        public int $id,
        public string $name,
        public SellUnit $sellUnit,
        public int $bedsPerRoom,
    ) {}

    public static function fromModel(RoomType $roomType): self
    {
        $bedsPerRoom = $roomType->sell_unit === SellUnit::Bed
            ? $roomType->rooms()->active()->ordered()->withCount(['beds' => fn ($query) => $query->active()])->get()
                ->firstWhere('beds_count', '>', 0)?->beds_count
            : null;

        return new self(
            id: $roomType->id,
            name: $roomType->name,
            sellUnit: $roomType->sell_unit,
            bedsPerRoom: (int) ($bedsPerRoom ?? self::DEFAULT_BEDS_PER_ROOM),
        );
    }
}

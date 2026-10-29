<?php

namespace App\Data\Onboarding;

use App\Models\Room;
use Spatie\LaravelData\Data;

/**
 * One saved room in the onboarding rooms step: its number, its room type and its active bed count. Load the model with
 * `withCount(['beds' => active])`.
 */
class OnboardingRoomData extends Data
{
    public function __construct(
        public string $number,
        public int $roomTypeId,
        public int $beds,
    ) {}

    public static function fromModel(Room $room): self
    {
        return new self(
            number: $room->number,
            roomTypeId: $room->room_type_id,
            beds: (int) $room->beds_count,
        );
    }
}

<?php

namespace App\Data\Onboarding;

use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Services\Rooms\RoomNumberRanges;
use Spatie\LaravelData\Data;

/**
 * One row of the onboarding Room numbers table: a group and its active rooms as text, with runs of numbers joined into
 * ranges ("102-134, 140"). Without groups there is one row with no group.
 */
class OnboardingRoomGroupRowData extends Data
{
    public function __construct(
        public string $key,
        public ?int $id,
        public string $name,
        public string $numbers,
    ) {}

    /**
     * The saved groups in their order, each with its rooms in room order. Rooms with no group form a row without an
     * id: the only row when the property has no group, else a last row that still needs a name.
     *
     * @return list<self>
     */
    public static function forProperty(Property $property): array
    {
        $ranges = app(RoomNumberRanges::class);
        $rooms = $property->rooms()->active()->ordered()->get(['id', 'number', 'room_group_id', 'position']);
        $rows = [];

        foreach ($property->roomGroups()->orderBy('position')->orderBy('id')->get() as $group) {
            /** @var RoomGroup $group */
            $rows[] = new self(
                key: 'group-'.$group->id,
                id: $group->id,
                name: $group->name,
                numbers: $ranges->join(array_values($rooms->where('room_group_id', $group->id)->map(fn (Room $room): string => $room->number)->all())),
            );
        }

        $ungrouped = array_values($rooms->whereNull('room_group_id')->map(fn (Room $room): string => $room->number)->all());

        if ($rows === [] || $ungrouped !== []) {
            $rows[] = new self(key: 'ungrouped', id: null, name: '', numbers: $ranges->join($ungrouped));
        }

        return $rows;
    }
}

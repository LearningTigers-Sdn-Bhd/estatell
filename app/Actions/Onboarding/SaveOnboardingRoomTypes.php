<?php

namespace App\Actions\Onboarding;

use App\Actions\Rooms\ArchiveRoom;
use App\Actions\Rooms\ArchiveRoomType;
use App\Actions\Rooms\CreateRoomType;
use App\Actions\Rooms\ReorderRoomTypes;
use App\Actions\Rooms\UpdateRoomType;
use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveOnboardingRoomTypes
{
    public function __construct(
        private CreateRoomType $createRoomType,
        private UpdateRoomType $updateRoomType,
        private ArchiveRoomType $archiveRoomType,
        private ArchiveRoom $archiveRoom,
        private ReorderRoomTypes $reorderRoomTypes,
    ) {}

    /**
     * Save the room types table in one transaction. A row with an id updates that room type, a row without one creates
     * a room type sold by room, and a saved room type sold by room that has no row is archived together with its rooms.
     * Room types sold by bed are never touched. Guests included follows max adults, so the basic rate has no extra
     * adult charge. The table order becomes the display order.
     *
     * @param  list<array{id: int|null, name: string, max_adults: int, max_children: int}>  $rows
     */
    public function __invoke(Property $property, User $actor, array $rows): void
    {
        DB::transaction(function () use ($property, $actor, $rows): void {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $saved = $property->roomTypes()->active()->where('sell_unit', SellUnit::Room)->get()->keyBy('id');
            $keptIds = array_filter(array_column($rows, 'id'));

            foreach ($saved->except($keptIds) as $roomType) {
                $this->archiveWithRooms($actor, $roomType);
            }

            $orderedIds = [];

            foreach ($rows as $row) {
                $attributes = [
                    'name' => $row['name'],
                    'max_adults' => $row['max_adults'],
                    'max_children' => $row['max_children'],
                    'base_occupancy' => $row['max_adults'],
                ];

                if ($row['id'] === null) {
                    $orderedIds[] = ($this->createRoomType)($property, $actor, [...$attributes, 'sell_unit' => SellUnit::Room])->id;

                    continue;
                }

                ($this->updateRoomType)($actor, $saved->findOrFail($row['id']), $attributes);
                $orderedIds[] = $row['id'];
            }

            $bedIds = array_values(array_map(
                intval(...),
                $property->roomTypes()->active()->where('sell_unit', SellUnit::Bed)->ordered()->pluck('id')->all(),
            ));

            ($this->reorderRoomTypes)($property, $actor, [...$orderedIds, ...$bedIds]);
        });
    }

    private function archiveWithRooms(User $actor, RoomType $roomType): void
    {
        $roomType->rooms()->active()->get()->each(fn (Room $room) => ($this->archiveRoom)($actor, $room));

        ($this->archiveRoomType)($actor, $roomType);
    }
}

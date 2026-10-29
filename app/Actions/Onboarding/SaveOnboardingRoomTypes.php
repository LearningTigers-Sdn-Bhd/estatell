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
     * a room type sold by room or by bed, and a saved room type that has no row is archived together with its rooms
     * (their beds stay as they are). Guests included follows max adults, so the basic rate has no extra adult charge.
     * The table order becomes the display order.
     *
     * @param  list<array{id: int|null, name: string, sell_unit: SellUnit, max_adults: int, max_children: int}>  $rows
     */
    public function __invoke(Property $property, User $actor, array $rows): void
    {
        DB::transaction(function () use ($property, $actor, $rows): void {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $saved = $property->roomTypes()->active()->get()->keyBy('id');
            $keptIds = array_filter(array_column($rows, 'id'));

            foreach ($saved->except($keptIds) as $roomType) {
                $this->archiveWithRooms($actor, $roomType);
            }

            $orderedIds = [];

            foreach ($rows as $row) {
                $attributes = [
                    'name' => $row['name'],
                    'sell_unit' => $row['sell_unit'],
                    'max_adults' => $row['max_adults'],
                    'max_children' => $row['max_children'],
                    'base_occupancy' => $row['max_adults'],
                ];

                if ($row['id'] === null) {
                    $orderedIds[] = ($this->createRoomType)($property, $actor, $attributes)->id;

                    continue;
                }

                ($this->updateRoomType)($actor, $saved->findOrFail($row['id']), $attributes);
                $orderedIds[] = $row['id'];
            }

            ($this->reorderRoomTypes)($property, $actor, $orderedIds);
        });
    }

    private function archiveWithRooms(User $actor, RoomType $roomType): void
    {
        $roomType->rooms()->active()->get()->each(fn (Room $room) => ($this->archiveRoom)($actor, $room));

        ($this->archiveRoomType)($actor, $roomType);
    }
}

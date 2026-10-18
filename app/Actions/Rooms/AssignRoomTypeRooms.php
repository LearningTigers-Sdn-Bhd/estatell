<?php

namespace App\Actions\Rooms;

use App\Enums\SellUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rooms\RoomAssignedNights;
use Illuminate\Support\Facades\DB;

class AssignRoomTypeRooms
{
    public function __construct(private RoomAssignedNights $assignedNights) {}

    /**
     * Save the rooms of a room type: each room's group, and rooms moved in from other types.
     *
     * All or nothing. Returns null when saved, or the refusal message and writes nothing. Only rooms that
     * differ change, and each one logs one `room.updated` activity with the changed fields.
     *
     * @param  list<array{room_id: int, room_group_id: int|null}>  $rows  Validated rows: active rooms and groups of the property.
     */
    public function __invoke(User $actor, RoomType $roomType, array $rows): ?string
    {
        return DB::transaction(function () use ($actor, $roomType, $rows): ?string {
            $roomType = RoomType::query()->lockForUpdate()->findOrFail($roomType->id);

            if ($roomType->archived_at !== null) {
                return __(':name is archived. Restore it before editing.', ['name' => $roomType->name]);
            }

            $groupByRoom = array_column($rows, 'room_group_id', 'room_id');

            $rooms = Room::query()
                ->where('property_id', $roomType->property_id)
                ->where(fn ($query) => $query
                    ->whereIn('id', array_keys($groupByRoom))
                    ->orWhere(fn ($query) => $query->where('room_type_id', $roomType->id)->whereNull('archived_at')))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $removed = $rooms->first(fn (Room $room): bool => $room->room_type_id === $roomType->id
                && $room->archived_at === null
                && ! array_key_exists($room->id, $groupByRoom));

            if ($removed !== null) {
                return __('Rooms can\'t be removed here. Add them on another room type.');
            }

            $changes = [];

            foreach ($rooms->filter(fn (Room $room): bool => array_key_exists($room->id, $groupByRoom) && $room->archived_at === null) as $room) {
                $movesIn = $room->room_type_id !== $roomType->id;

                if ($movesIn) {
                    $refusal = $this->refuseMove($room, $roomType);

                    if ($refusal !== null) {
                        return $refusal;
                    }

                    $room->room_type_id = $roomType->id;
                }

                $room->room_group_id = $groupByRoom[$room->id];

                if ($room->isDirty()) {
                    $changes[] = [$room, array_keys($room->getDirty())];
                }
            }

            foreach ($changes as [$room, $changed]) {
                $room->save();

                activity()
                    ->performedOn($room)
                    ->causedBy($actor)
                    ->event('room.updated')
                    ->withProperties(['property_id' => $room->property_id, 'changed' => $changed])
                    ->log('Room updated');
            }

            return null;
        });
    }

    /**
     * The message that stops a room moving into the room type, or null when it may move.
     */
    private function refuseMove(Room $room, RoomType $roomType): ?string
    {
        if ($roomType->sell_unit === SellUnit::Room && $room->beds()->exists()) {
            return __(':number has beds. Choose a room type that sells beds.', ['number' => $room->number]);
        }

        $firstNight = $this->assignedNights->firstDate($room);

        if ($firstNight !== null) {
            return __(':number has stays assigned from :date. Move them first.', ['number' => $room->number, 'date' => $firstNight->translatedFormat('j M')]);
        }

        return null;
    }
}

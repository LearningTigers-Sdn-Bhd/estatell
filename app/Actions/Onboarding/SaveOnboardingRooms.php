<?php

namespace App\Actions\Onboarding;

use App\Actions\Rooms\ArchiveRoom;
use App\Actions\Rooms\CreateRoomGroup;
use App\Actions\Rooms\GenerateBeds;
use App\Actions\Rooms\GenerateRooms;
use App\Actions\Rooms\ReorderRoomGroups;
use App\Actions\Rooms\ReorderRooms;
use App\Actions\Rooms\UpdateRoom;
use App\Actions\Rooms\UpdateRoomGroup;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveOnboardingRooms
{
    public function __construct(
        private CreateRoomGroup $createRoomGroup,
        private UpdateRoomGroup $updateRoomGroup,
        private ReorderRoomGroups $reorderRoomGroups,
        private ArchiveRoom $archiveRoom,
        private UpdateRoom $updateRoom,
        private GenerateRooms $generateRooms,
        private ReorderRooms $reorderRooms,
        private GenerateBeds $generateBeds,
    ) {}

    /**
     * Save the rooms step in one transaction. Groups are created or renamed in table order. A saved room whose number
     * is in no row is archived; a saved number in a row takes that row's group and its room type; a new number becomes
     * a room. Rooms then follow the table order, and every room of a bed-sold type without an active bed gets Beds per
     * room beds. When a number is taken by the time of the save, nothing is written and the taken numbers are returned.
     *
     * @param  list<array{key: string, id: int|null, name: string, numbers: list<string>}>  $groups
     * @param  array<string, int>  $typeByNumber  Room type ids keyed by the number in lower case.
     * @param  array<int, int>  $bedsPerRoom  Keyed by bed-sold room type id.
     * @return list<string> The taken numbers, empty when saved.
     */
    public function __invoke(Property $property, User $actor, bool $usesGroups, array $groups, array $typeByNumber, array $bedsPerRoom): array
    {
        try {
            DB::transaction(function () use ($property, $actor, $usesGroups, $groups, $typeByNumber, $bedsPerRoom): void {
                Property::query()->lockForUpdate()->findOrFail($property->id);

                $groupIds = $usesGroups ? $this->saveGroups($property, $actor, $groups) : [];
                $saved = $property->rooms()->active()->get()->keyBy(fn (Room $room): string => mb_strtolower($room->number));
                $listed = [];

                foreach ($groups as $row) {
                    foreach ($row['numbers'] as $number) {
                        $listed[mb_strtolower($number)] = true;
                    }
                }

                foreach ($saved->diffKeys($listed) as $room) {
                    ($this->archiveRoom)($actor, $room);
                }

                foreach ($groups as $row) {
                    $groupId = $groupIds[$row['key']] ?? null;
                    $batch = [];

                    foreach ($row['numbers'] as $number) {
                        $typeId = $typeByNumber[mb_strtolower($number)];
                        $room = $saved->get(mb_strtolower($number));

                        if ($room !== null) {
                            ($this->updateRoom)($actor, $room, ['room_type_id' => $typeId, 'room_group_id' => $groupId]);

                            continue;
                        }

                        $batch[$typeId][] = ['number' => $number, 'group' => null];
                    }

                    $group = $groupId === null ? null : RoomGroup::query()->findOrFail($groupId);

                    foreach ($batch as $typeId => $plan) {
                        $roomType = $property->roomTypes()->findOrFail($typeId);
                        $result = ($this->generateRooms)($property, $actor, $roomType, $group, $plan);

                        if ($result['conflicts'] !== []) {
                            throw new RoomNumbersTaken($result['conflicts']);
                        }
                    }
                }

                $ids = $property->rooms()->active()->pluck('id', 'number')->mapWithKeys(fn (int $id, string $number): array => [mb_strtolower($number) => $id]);

                ($this->reorderRooms)($property, $actor, array_map(
                    fn (string $number): int => $ids[$number],
                    array_keys($listed),
                ));

                foreach ($bedsPerRoom as $typeId => $count) {
                    $roomIds = $property->rooms()->active()
                        ->where('room_type_id', $typeId)
                        ->whereDoesntHave('beds', fn ($query) => $query->active())
                        ->pluck('id')->map(intval(...))->all();

                    $roomIds = array_values($roomIds);

                    if ($roomIds !== []) {
                        ($this->generateBeds)($property, $actor, $roomIds, $count);
                    }
                }
            });
        } catch (RoomNumbersTaken $taken) {
            return $taken->numbers;
        }

        return [];
    }

    /**
     * Create or rename the groups and put them in table order; saved groups missing from the table keep their place
     * after the others. Returns the group id of each row key.
     *
     * @param  list<array{key: string, id: int|null, name: string, numbers: list<string>}>  $groups
     * @return array<string, int>
     */
    private function saveGroups(Property $property, User $actor, array $groups): array
    {
        $saved = $property->roomGroups()->get()->keyBy('id');
        $ids = [];

        foreach ($groups as $row) {
            if ($row['id'] !== null) {
                ($this->updateRoomGroup)($actor, $saved->get($row['id']), ['name' => $row['name']]);
                $ids[$row['key']] = $row['id'];

                continue;
            }

            $ids[$row['key']] = ($this->createRoomGroup)($property, $actor, ['name' => $row['name']])->id;
        }

        $order = array_values(array_map(intval(...), $ids));
        $rest = array_values($property->roomGroups()->whereNotIn('id', $order)->orderBy('position')->orderBy('id')->pluck('id')->map(intval(...))->all());

        ($this->reorderRoomGroups)($property, $actor, [...$order, ...$rest]);

        return $ids;
    }
}

<?php

namespace App\Actions\Rooms;

use App\Events\InventoryChanged;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomStatus;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rooms\RoomNumberAvailability;
use Illuminate\Support\Facades\DB;

class GenerateRooms
{
    public function __construct(private RoomNumberAvailability $availability) {}

    /**
     * Create many rooms of one room type from a plan made by RoomNumberGenerator.
     *
     * All or nothing: when an active room already uses any of the numbers, nothing is written and
     * the taken numbers are returned. A plan entry with a group name is placed in the group with that
     * name (created when missing). Entries without one go into `$group`.
     *
     * @param  list<array{number: string, group: string|null}>  $plan
     * @return array{created: int, conflicts: list<string>}
     */
    public function __invoke(Property $property, User $actor, RoomType $roomType, ?RoomGroup $group, array $plan): array
    {
        return DB::transaction(function () use ($property, $actor, $roomType, $group, $plan): array {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $numbers = array_column($plan, 'number');

            $conflicts = $this->availability->taken($property, $numbers);

            if ($conflicts !== []) {
                return ['created' => 0, 'conflicts' => $conflicts];
            }

            $groupIds = $this->groupIdsByName($property, array_filter(array_unique(array_column($plan, 'group'))));
            $position = (int) $property->rooms()->max('position');

            foreach ($plan as $entry) {
                $room = new Room([
                    'room_type_id' => $roomType->id,
                    'room_group_id' => $entry['group'] !== null ? $groupIds[mb_strtolower($entry['group'])] : $group?->id,
                    'number' => $entry['number'],
                    'position' => ++$position,
                ]);
                $room->property_id = $property->id;
                $room->save();

                $status = new RoomStatus;
                $status->property_id = $property->id;
                $room->status()->save($status);
            }

            event(InventoryChanged::forRoomType($property->id, $roomType->id));

            activity()
                ->causedBy($actor)
                ->event('room.generated')
                ->withProperties([
                    'property_id' => $property->id,
                    'room_type_id' => $roomType->id,
                    'count' => count($plan),
                    'first' => $numbers[0] ?? null,
                    'last' => $numbers[count($numbers) - 1] ?? null,
                ])
                ->log('Rooms generated');

            return ['created' => count($plan), 'conflicts' => []];
        });
    }

    /**
     * Find or create one room group per name. Returns group ids keyed by lower-case name.
     *
     * @param  array<int, string>  $names
     * @return array<string, int>
     */
    private function groupIdsByName(Property $property, array $names): array
    {
        $ids = [];
        $position = (int) $property->roomGroups()->max('position');

        foreach ($names as $name) {
            $existing = $property->roomGroups()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

            if ($existing === null) {
                $existing = new RoomGroup(['name' => $name, 'position' => ++$position]);
                $existing->property_id = $property->id;
                $existing->save();
            }

            $ids[mb_strtolower($name)] = $existing->id;
        }

        return $ids;
    }
}

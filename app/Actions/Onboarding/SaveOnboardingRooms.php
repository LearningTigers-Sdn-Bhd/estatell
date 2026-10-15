<?php

namespace App\Actions\Onboarding;

use App\Actions\Rooms\CreateRoomGroup;
use App\Actions\Rooms\GenerateRooms;
use App\Models\Property;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rooms\RoomNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaveOnboardingRooms
{
    public function __construct(
        private CreateRoomGroup $createRoomGroup,
        private GenerateRooms $generateRooms,
        private RoomNumberGenerator $generator,
    ) {}

    /**
     * Save the rooms table in one transaction: create the new groups in table order, then generate the rooms of each
     * range row. A group row with an id is already saved and stays as it is. A range refers to its group by the row
     * key. When a number is taken by the time of the save, nothing is written and the taken numbers are returned.
     *
     * @param  list<array{key: string, id: int|null, name: string}>  $groups
     * @param  list<array{room_type_id: int, group_key: string|null, prefix: string, from: string, to: string}>  $ranges
     * @return array{created: int, conflicts: list<string>}
     */
    public function __invoke(Property $property, User $actor, array $groups, array $ranges): array
    {
        try {
            return DB::transaction(function () use ($property, $actor, $groups, $ranges): array {
                $savedGroups = $property->roomGroups()->get()->keyBy('id');
                $byKey = [];

                foreach ($groups as $row) {
                    $byKey[$row['key']] = $row['id'] !== null
                        ? $savedGroups->get($row['id'])
                        : ($this->createRoomGroup)($property, $actor, ['name' => $row['name']]);
                }

                $created = 0;

                foreach ($ranges as $range) {
                    $roomType = RoomType::query()->where('property_id', $property->id)->findOrFail($range['room_type_id']);
                    $group = $range['group_key'] !== null ? $this->groupFor($byKey, $range['group_key']) : null;
                    $plan = $this->generator->generate(RoomNumberGenerator::MODE_RANGE, $range);

                    $result = ($this->generateRooms)($property, $actor, $roomType, $group, $plan);

                    if ($result['conflicts'] !== []) {
                        throw new RoomNumbersTaken($result['conflicts']);
                    }

                    $created += $result['created'];
                }

                return ['created' => $created, 'conflicts' => []];
            });
        } catch (RoomNumbersTaken $taken) {
            return ['created' => 0, 'conflicts' => $taken->numbers];
        }
    }

    /**
     * @param  array<string, RoomGroup|null>  $byKey
     */
    private function groupFor(array $byKey, string $key): RoomGroup
    {
        return $byKey[$key] ?? throw new RuntimeException("Unknown room group key {$key}.");
    }
}

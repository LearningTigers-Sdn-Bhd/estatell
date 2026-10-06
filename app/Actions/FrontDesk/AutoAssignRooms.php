<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\AssignBed;
use App\Actions\Reservations\AssignmentResult;
use App\Actions\Reservations\AssignRoom;
use App\Enums\SellUnit;
use App\Models\Bed;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Availability\FreeUnits;
use Illuminate\Support\Facades\DB;

class AutoAssignRooms
{
    public function __construct(
        private FreeUnits $freeUnits,
        private AssignRoom $assignRoom,
        private AssignBed $assignBed,
    ) {}

    /**
     * Give a unit that has no room or bed a free one for the whole stay. Rooms and beds are tried in the order of
     * the room group, then the room position, with a Dirty room after a Clean or Inspected one. Each try goes through
     * `AssignRoom` or `AssignBed`, which lock and check again, so a pick that a parallel request took is skipped and
     * the next one is tried. The caller decides when to run it: `auto_assign_rooms` is a property setting.
     * Returns `no_free_unit` when nothing is free. See docs/18-front-desk/02-room-and-bed-assignment.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit): AssignmentResult
    {
        $roomType = RoomType::query()->findOrFail($unit->room_type_id);
        $isBed = $roomType->sell_unit === SellUnit::Bed;
        $free = array_map(fn ($item): int => $item->id, $this->freeUnits->forStay($roomType, $unit->arrival_date, $unit->departure_date, $unit->id));
        $result = AssignmentResult::refused(AssignmentResult::REFUSAL_NO_FREE_UNIT);

        foreach ($this->inOrder($free, $isBed) as $id) {
            $result = $isBed
                ? ($this->assignBed)($actor, $unit, Bed::query()->findOrFail($id))
                : ($this->assignRoom)($actor, $unit, Room::query()->findOrFail($id));

            if (! $result->isRefused()) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $unitIds  Room ids, or bed ids for a Bed room type
     * @return list<int>
     */
    private function inOrder(array $unitIds, bool $isBed): array
    {
        if ($unitIds === []) {
            return [];
        }

        $query = DB::table('rooms')
            ->leftJoin('room_groups', 'room_groups.id', '=', 'rooms.room_group_id')
            ->leftJoin('room_statuses', 'room_statuses.room_id', '=', 'rooms.id');

        if ($isBed) {
            $query->join('beds', 'beds.room_id', '=', 'rooms.id')->whereIn('beds.id', $unitIds)->select('beds.id');
        } else {
            $query->whereIn('rooms.id', $unitIds)->select('rooms.id');
        }

        return $query
            ->orderByRaw("CASE WHEN room_statuses.status = 'dirty' THEN 1 ELSE 0 END")
            ->orderByRaw('room_groups.position IS NULL')
            ->orderBy('room_groups.position')
            ->orderBy('rooms.position')
            ->orderBy('rooms.id')
            ->when($isBed, fn ($query) => $query->orderBy('beds.position')->orderBy('beds.id'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}

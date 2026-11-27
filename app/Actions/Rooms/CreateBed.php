<?php

namespace App\Actions\Rooms;

use App\Actions\Housekeeping\CreateBedStatus;
use App\Enums\SellUnit;
use App\Models\Bed;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBed
{
    public function __construct(private CreateBedStatus $createBedStatus) {}

    /**
     * Add a bed at the end of the room's list.
     *
     * Returns null when the room is archived or its room type does not sell beds.
     *
     * @param  array<string, mixed>  $attributes  Validated bed fields.
     */
    public function __invoke(User $actor, Room $room, array $attributes): ?Bed
    {
        return DB::transaction(function () use ($actor, $room, $attributes): ?Bed {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);

            if ($room->archived_at !== null || $room->roomType->sell_unit !== SellUnit::Bed) {
                return null;
            }

            $bed = new Bed($attributes);
            $bed->room_id = $room->id;
            $bed->position = ((int) $room->beds()->max('position')) + 1;
            $bed->save();
            ($this->createBedStatus)($bed, $room);

            activity()
                ->performedOn($bed)
                ->causedBy($actor)
                ->event('bed.created')
                ->withProperties(['property_id' => $room->property_id, 'room_id' => $room->id, 'label' => $bed->label])
                ->log('Bed created');

            return $bed;
        });
    }
}

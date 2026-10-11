<?php

namespace App\Actions\Reservations;

use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Availability\FreeUnits;
use Illuminate\Support\Facades\DB;

class AssignRoom
{
    public function __construct(private FreeUnits $freeUnits) {}

    /**
     * Put a unit in a room for the whole stay: `room_id` on every night. The unit and its room type are locked first,
     * so two staff cannot give the same room to two units. Every check runs before the write, so a refusal writes and
     * logs nothing. A unit that already has a room is moved. Mid-stay moves belong to module 18.
     * See docs/12-reservations/05-booking-form.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, Room $room): AssignmentResult
    {
        return DB::transaction(function () use ($actor, $unit, $room): AssignmentResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $roomType = RoomType::query()->lockForUpdate()->findOrFail($locked->room_type_id);

            if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true)) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_UNIT_NOT_OPEN);
            }

            if ($locked->stay_type === StayType::DayUse) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_DAY_USE_UNIT);
            }

            if ($roomType->sell_unit !== SellUnit::Room || ! $this->freeUnits->isSellable($roomType, $room->id)) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_ROOM_NOT_FOUND);
            }

            $taken = $this->freeUnits->firstTakenNight($roomType, $room->id, $locked->arrival_date, $locked->departure_date, $locked->id, $locked->arrival_time, $locked->departure_time);

            if ($taken !== null) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_ROOM_NOT_FREE, $taken);
            }

            $locked->nights()->update(['room_id' => $room->id, 'bed_id' => null]);

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.room_assigned')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'room_id' => $room->id,
                ])
                ->log('Room assigned to unit');

            return AssignmentResult::assigned();
        });
    }
}

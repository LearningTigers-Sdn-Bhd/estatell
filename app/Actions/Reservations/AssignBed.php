<?php

namespace App\Actions\Reservations;

use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Availability\FreeUnits;
use Illuminate\Support\Facades\DB;

class AssignBed
{
    public function __construct(private FreeUnits $freeUnits) {}

    /**
     * Put a unit on a bed for the whole stay: `bed_id` on every night. The unit and its room type are locked first,
     * so two staff cannot give the same bed to two units. Every check runs before the write, so a refusal writes and
     * logs nothing. A unit that already has a bed is moved. See docs/12-reservations/05-booking-form.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, Bed $bed): AssignmentResult
    {
        return DB::transaction(function () use ($actor, $unit, $bed): AssignmentResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $roomType = RoomType::query()->lockForUpdate()->findOrFail($locked->room_type_id);

            if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true)) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_UNIT_NOT_OPEN);
            }

            if ($locked->stay_type === StayType::DayUse) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_DAY_USE_UNIT);
            }

            if ($roomType->sell_unit !== SellUnit::Bed || ! $this->freeUnits->isSellable($roomType, $bed->id)) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_BED_NOT_FOUND);
            }

            $taken = $this->freeUnits->firstTakenNight($roomType, $bed->id, $locked->arrival_date, $locked->departure_date, $locked->id);

            if ($taken !== null) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_BED_NOT_FREE, $taken);
            }

            $locked->nights()->update(['room_id' => null, 'bed_id' => $bed->id]);

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.bed_assigned')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'bed_id' => $bed->id,
                ])
                ->log('Bed assigned to unit');

            return AssignmentResult::assigned();
        });
    }
}

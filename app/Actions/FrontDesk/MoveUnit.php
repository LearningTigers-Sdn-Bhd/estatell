<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\AssignmentResult;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Availability\FreeUnits;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class MoveUnit
{
    public function __construct(
        private FreeUnits $freeUnits,
        private CurrentBusinessDate $currentBusinessDate,
    ) {}

    /**
     * Put a unit in another room or on another bed from a given night onward, with a reason. Earlier nights keep
     * their room. The unit and the room types are locked first (in id order), so two staff cannot give the same room
     * to two units. Every check runs before the write, so a refusal writes and logs nothing.
     *
     * The first night must be inside the stay and not before the business date (`night_in_past`). A target in another
     * room type is allowed only when that type has the same sell unit and is linked to the unit's rate plan
     * (`room_type_not_allowed`), and only for the whole stay (`whole_stay_required`), because a unit has one room
     * type. The nights keep their booked prices. Permissions are checked by the route.
     * See docs/18-front-desk/02-room-and-bed-assignment.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, Room|Bed $target, CarbonInterface $fromNight, string $reason): AssignmentResult
    {
        return DB::transaction(function () use ($actor, $unit, $target, $fromNight, $reason): AssignmentResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $isBed = $target instanceof Bed;
            $targetTypeId = $isBed ? $target->room()->firstOrFail()->room_type_id : $target->room_type_id;
            $types = RoomType::query()->whereIn('id', array_unique([$locked->room_type_id, $targetTypeId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $targetType = $types[$targetTypeId];

            if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true)) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_UNIT_NOT_OPEN);
            }

            if (trim($reason) === '') {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_REASON_REQUIRED);
            }

            $from = CarbonImmutable::instance($fromNight)->startOfDay();

            if ($from->lt($locked->arrival_date->startOfDay()) || $from->gte($locked->departure_date->startOfDay())) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_NIGHT_INVALID);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate !== null && $from->lt($businessDate->startOfDay())) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_NIGHT_IN_PAST);
            }

            $wantedSellUnit = $isBed ? SellUnit::Bed : SellUnit::Room;

            if ($targetType->sell_unit !== $wantedSellUnit || ! $this->freeUnits->isSellable($targetType, $target->id)) {
                return AssignmentResult::refused($isBed ? AssignmentResult::REFUSAL_BED_NOT_FOUND : AssignmentResult::REFUSAL_ROOM_NOT_FOUND);
            }

            $changesType = $targetTypeId !== $locked->room_type_id;

            if ($changesType) {
                if ($targetType->sell_unit !== $types[$locked->room_type_id]->sell_unit
                    || ! $locked->ratePlan()->firstOrFail()->roomTypes()->whereKey($targetTypeId)->exists()) {
                    return AssignmentResult::refused(AssignmentResult::REFUSAL_ROOM_TYPE_NOT_ALLOWED);
                }

                if (! $from->equalTo($locked->arrival_date->startOfDay())) {
                    return AssignmentResult::refused(AssignmentResult::REFUSAL_WHOLE_STAY_REQUIRED);
                }
            }

            $nights = $locked->nights()->whereDate('date', '>=', $from->toDateString())->get();

            if (! $changesType && $nights->every(fn (UnitNight $night): bool => ($isBed ? $night->bed_id : $night->room_id) === $target->id)) {
                return AssignmentResult::refused(AssignmentResult::REFUSAL_UNCHANGED);
            }

            $taken = $this->freeUnits->firstTakenNight($targetType, $target->id, $from, $locked->departure_date, $locked->id);

            if ($taken !== null) {
                return AssignmentResult::refused($isBed ? AssignmentResult::REFUSAL_BED_NOT_FREE : AssignmentResult::REFUSAL_ROOM_NOT_FREE, $taken);
            }

            $previous = [
                'room_ids' => $nights->pluck('room_id')->filter()->unique()->values()->map(fn (mixed $id): int => (int) $id)->all(),
                'bed_ids' => $nights->pluck('bed_id')->filter()->unique()->values()->map(fn (mixed $id): int => (int) $id)->all(),
            ];

            $locked->nights()->whereDate('date', '>=', $from->toDateString())->update([
                'room_id' => $isBed ? null : $target->id,
                'bed_id' => $isBed ? $target->id : null,
            ]);

            if ($changesType) {
                $locked->room_type_id = $targetTypeId;
                $locked->save();
            }

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_moved')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'from_night' => $from->toDateString(),
                    'room_id' => $isBed ? null : $target->id,
                    'bed_id' => $isBed ? $target->id : null,
                    'room_type_id' => $targetTypeId,
                    'previous' => $previous,
                    'reason' => trim($reason),
                ])
                ->log('Unit moved');

            return AssignmentResult::assigned();
        });
    }
}

<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\AssignmentResult;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class MoveUnitStay
{
    public const SAME_ROOM_REASON = 'Stay dates moved on the Timeline';

    public function __construct(
        private ChangeUnitDates $changeUnitDates,
        private MoveUnit $moveUnit,
    ) {}

    /**
     * Move a Confirmed unit to new dates and to a room or bed in one step, for the Timeline drag. It runs
     * `ChangeUnitDates` (a new arrival is a new booking of the unit, so it has no fee) and then `MoveUnit` to the target
     * from the new arrival, in one transaction. A refusal of either rolls both back, so nothing is written or logged
     * and the refusal code comes back. A move that finds the unit already there (`unchanged`) counts as done.
     *
     * Any other status is refused as `invalid_status`. `MoveUnit` needs a reason when the room changes. A sideways shift
     * that keeps the room (every night is already in the target) uses a fixed reason when `$reason` is blank. The
     * restriction override reason is passed on only by the route for a user with `rates.override-restrictions`.
     * Permissions are checked by the route. See docs/18-front-desk/07-timeline-editing.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, CarbonInterface $arrival, CarbonInterface $departure, Room|Bed $target, ?string $reason, ?string $restrictionOverrideReason = null): AssignmentResult
    {
        try {
            return DB::transaction(function () use ($actor, $unit, $arrival, $departure, $target, $reason, $restrictionOverrideReason): AssignmentResult {
                $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

                if ($locked->status !== UnitStatus::Confirmed) {
                    throw new MoveUnitStayRefused(AssignmentResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS));
                }

                $newArrival = CarbonImmutable::instance($arrival)->startOfDay();
                $newDeparture = CarbonImmutable::instance($departure)->startOfDay();
                $keepsRoom = $this->keepsRoom($locked, $target);

                if (! $newArrival->equalTo($locked->arrival_date->startOfDay()) || ! $newDeparture->equalTo($locked->departure_date->startOfDay())) {
                    $dates = ($this->changeUnitDates)($actor, $locked, $newArrival, $newDeparture, restrictionOverrideReason: $restrictionOverrideReason);

                    if ($dates->isRefused()) {
                        throw new MoveUnitStayRefused(AssignmentResult::refused((string) $dates->refusal));
                    }
                }

                $moveReason = trim((string) $reason);

                if ($moveReason === '' && $keepsRoom) {
                    $moveReason = self::SAME_ROOM_REASON;
                }

                $moved = ($this->moveUnit)($actor, $locked->refresh(), $target, $newArrival, $moveReason);

                if ($moved->isRefused() && $moved->refusal !== AssignmentResult::REFUSAL_UNCHANGED) {
                    throw new MoveUnitStayRefused($moved);
                }

                return AssignmentResult::assigned();
            });
        } catch (MoveUnitStayRefused $refused) {
            return $refused->result;
        }
    }

    /**
     * Whether every night of the unit already sits in the target, so only the dates change.
     */
    private function keepsRoom(ReservationUnit $unit, Room|Bed $target): bool
    {
        $nights = $unit->nights()->get();

        return $nights->isNotEmpty() && $nights->every(fn ($night): bool => ($target instanceof Bed ? $night->bed_id : $night->room_id) === $target->id);
    }
}

<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\AssignBed;
use App\Actions\Reservations\AssignRoom;
use App\Data\Reservations\CollectNowData;
use App\Enums\EndedStayChoice;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\FrontDesk\EndedStayCheckIn;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CheckInEndedStay
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private EndedStayCheckIn $endedStayCheckIn,
        private CheckInAndCollect $checkInAndCollect,
        private ChangeUnitDates $changeUnitDates,
        private AssignRoom $assignRoom,
        private AssignBed $assignBed,
    ) {}

    /**
     * Check in a Confirmed unit whose departure date is the business date. `ArrivedLastNight` checks it in for its last
     * night, with `$arrivedAt` (`H:i`, property time) as the real arrival, and every unposted night is charged.
     * `ArrivingToday` moves the stay to today → tomorrow, keeps the room or bed of the last night when it is free, and
     * checks in, in one transaction, so a refusal saves neither.
     * Permissions are checked by the route and the Form Request.
     * See docs/18-front-desk/01-check-in-and-check-out.md#check-in-after-the-stay-ended.
     */
    public function __invoke(User $actor, ReservationUnit $unit, EndedStayChoice $choice, ?string $arrivedAt = null, ?string $nationality = null, bool $withoutCard = false, ?string $creditOverrideReason = null, ?CollectNowData $collect = null): CheckInAndCollectResult
    {
        $businessDate = $this->currentBusinessDate->date($unit->property);

        if ($businessDate === null) {
            return new CheckInAndCollectResult(UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE));
        }

        if (! $this->endedStayCheckIn->isOffered($unit, $businessDate)) {
            return new CheckInAndCollectResult(UnitStayResult::refused(UnitStayResult::REFUSAL_STAY_ENDED));
        }

        if ($choice === EndedStayChoice::ArrivingToday) {
            return $this->moveToToday($actor, $unit, $businessDate, $nationality, $withoutCard, $creditOverrideReason, $collect);
        }

        $arrived = $this->endedStayCheckIn->arrivedAt($unit, (string) $arrivedAt);

        if ($arrived->gt(now())) {
            return new CheckInAndCollectResult(UnitStayResult::refused(UnitStayResult::REFUSAL_ARRIVED_AT_IN_FUTURE));
        }

        return ($this->checkInAndCollect)($actor, $unit, $nationality, $withoutCard, $creditOverrideReason, $collect, $arrived, $this->endedStayCheckIn->lastNight($unit));
    }

    private function moveToToday(User $actor, ReservationUnit $unit, CarbonImmutable $businessDate, ?string $nationality, bool $withoutCard, ?string $creditOverrideReason, ?CollectNowData $collect): CheckInAndCollectResult
    {
        try {
            return DB::transaction(function () use ($actor, $unit, $businessDate, $nationality, $withoutCard, $creditOverrideReason, $collect): CheckInAndCollectResult {
                $lastNight = $unit->nights()->with(['room', 'bed'])->whereDate('date', $this->endedStayCheckIn->lastNight($unit))->first();
                $moved = ($this->changeUnitDates)($actor, $unit, $businessDate, $businessDate->addDay());

                if ($moved->isRefused()) {
                    throw new UnitStayRefused(UnitStayResult::refused((string) $moved->refusal));
                }

                $this->keepRoom($actor, $moved->unit, $lastNight);

                $result = ($this->checkInAndCollect)($actor, $moved->unit, $nationality, $withoutCard, $creditOverrideReason, $collect);

                if ($result->stay->isRefused()) {
                    throw new UnitStayRefused($result->stay);
                }

                if ($result->paymentRefusal !== null) {
                    throw new CollectNowRefused($result);
                }

                return $result;
            });
        } catch (UnitStayRefused $refused) {
            return new CheckInAndCollectResult($refused->result);
        } catch (CollectNowRefused $refused) {
            return new CheckInAndCollectResult($refused->result->stay, [], $refused->result->paymentRefusal);
        }
    }

    /**
     * Moved nights start with no room. A room that is taken today is left unassigned, so `CheckInUnit` auto-assigns one
     * or refuses `no_room_assigned`.
     */
    private function keepRoom(User $actor, ReservationUnit $unit, ?UnitNight $lastNight): void
    {
        if ($lastNight?->room !== null) {
            ($this->assignRoom)($actor, $unit, $lastNight->room);
        } elseif ($lastNight?->bed !== null) {
            ($this->assignBed)($actor, $unit, $lastNight->bed);
        }
    }
}

<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\SyncReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\User;
use App\Services\Availability\DayUseAvailability;
use App\Services\Catalogues\PolicyChargeCalculator;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class ExtendDepartureTime
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PolicyChargeCalculator $policyChargeCalculator,
        private DayUseAvailability $dayUseAvailability,
        private PostUnitPolicyFee $postUnitPolicyFee,
        private SyncReservationStatus $syncReservationStatus,
    ) {}

    /**
     * Late check-out: move the expected `departure_time` of a CheckedIn unit later, on its departure date, and post
     * the late check-out policy fee. `$newTime` is `H:i`. It must be later than the current `departure_time`, and
     * `H:i` cannot pass midnight. The unit row is locked first. Every check runs before the first write, so a
     * refusal writes and logs nothing. The fee, `$doNotCharge`, `$reason` and `$expectedFee` work as in `CancelUnit`.
     * A second extension on the same day posts the fee again, and staff can choose Do not charge. The date and the
     * night audit do not change. A live day use block in the unit's room (the room of its last night) that overlaps
     * the hours between the current and the new time refuses the extension as `room_not_free`. Permissions are checked by the route (`front-desk.check-out`, and
     * `front-desk.waive-fees` for Do not charge). See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, string $newTime, bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null): UnitStayResult
    {
        return DB::transaction(function () use ($actor, $unit, $newTime, $doNotCharge, $reason, $expectedFee): UnitStayResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if ($locked->status !== UnitStatus::CheckedIn) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
            }

            if ($businessDate->toDateString() !== $locked->departure_date->toDateString()) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_NOT_DEPARTURE_DATE);
            }

            $previousTime = substr($locked->departure_time, 0, 5);

            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $newTime) !== 1 || $newTime <= $previousTime) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_TIME_NOT_LATER);
            }

            $roomId = $locked->nights()->whereNotNull('room_id')->orderByDesc('date')->value('room_id');

            if ($roomId !== null && $this->dayUseAvailability->hasLiveBlockBetween($locked->property_id, $roomId, $locked->departure_date, $previousTime, $newTime)) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_ROOM_NOT_FREE);
            }

            $reason = filled($reason) ? trim((string) $reason) : null;

            if ($doNotCharge && $reason === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_REASON_REQUIRED);
            }

            $policy = RoomRevenuePolicy::query()->where('property_id', $locked->property_id)->where('code', SystemTransactionCode::LateCheckoutRevenue)->first();

            if ($policy === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_POLICY_NOT_FOUND);
            }

            $charge = $this->policyChargeCalculator->calculate($policy, $locked);
            $fee = (string) $charge->amount->getAmount();
            $postsFee = ! $doNotCharge && ! $charge->isFree();

            if ($postsFee && $expectedFee !== null && ! BigDecimal::of($expectedFee)->isEqualTo($fee)) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_FEE_CHANGED);
            }

            $feeLine = null;

            if ($postsFee) {
                $posted = ($this->postUnitPolicyFee)($actor, $locked, $policy, $charge->amount, __('Late check-out fee'), 'late_checkout', $businessDate->toDateString());

                if ($posted->isRefused()) {
                    return UnitStayResult::refused((string) $posted->refusal);
                }

                $feeLine = $posted->line();
            }

            $locked->departure_time = $newTime.':00';
            $locked->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_departure_time_extended')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'previous_departure_time' => $previousTime,
                    'departure_time' => $newTime,
                    'fee' => $fee,
                    'fee_folio_line_id' => $feeLine?->id,
                    'did_not_charge' => $doNotCharge,
                    'reason' => $reason,
                    'business_date' => $businessDate->toDateString(),
                ])
                ->log('Late check-out');

            ($this->syncReservationStatus)($locked->reservation);

            return UnitStayResult::done($locked);
        });
    }
}

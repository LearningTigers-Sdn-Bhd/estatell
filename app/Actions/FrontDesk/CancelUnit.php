<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\SyncReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\User;
use App\Services\Catalogues\PolicyChargeCalculator;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

class CancelUnit
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PolicyChargeCalculator $policyChargeCalculator,
        private PostUnitPolicyFee $postUnitPolicyFee,
        private RemoveUnpostedAddOns $removeUnpostedAddOns,
        private ReleaseUnitRooms $releaseUnitRooms,
        private SyncReservationStatus $syncReservationStatus,
    ) {}

    /**
     * Cancel one Confirmed unit: set `UnitStatus::Cancelled`, post the cancellation policy fee and sync the
     * reservation status. The unit row is locked first, so a double click cannot cancel it twice. Every check runs
     * before the first write, so a refusal writes and logs nothing. The nights stay as history with their prices, and
     * their rooms and beds are released (`ReleaseUnitRooms`).
     *
     * The fee comes from `PolicyChargeCalculator` on the business date and posts through `PostUnitPolicyFee`.
     * `$expectedFee` is the fee the form showed (a decimal string): when it differs from the fee now, the Action
     * refuses with `fee_changed`. `$doNotCharge` posts no fee and needs a `$reason`; the route passes it only for a
     * user with `front-desk.waive-fees`. `$removeAddOns` deletes the unit's unposted add-ons with
     * `RemoveUnpostedAddOns`; a posted add-on stays, and the correction is `ReverseLine`.
     * Permissions are checked by the route. See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null, bool $removeAddOns = false): UnitStayResult
    {
        return DB::transaction(function () use ($actor, $unit, $doNotCharge, $reason, $expectedFee, $removeAddOns): UnitStayResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if (! $locked->status->canTransitionTo(UnitStatus::Cancelled)) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $reason = filled($reason) ? trim((string) $reason) : null;

            if ($doNotCharge && $reason === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_REASON_REQUIRED);
            }

            $policy = RoomRevenuePolicy::query()->where('property_id', $locked->property_id)->where('code', SystemTransactionCode::CancellationRevenue)->first();

            if ($policy === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_POLICY_NOT_FOUND);
            }

            $charge = $this->policyChargeCalculator->calculate($policy, $locked);
            $feeAmount = (string) $charge->amount->getAmount();
            $postsFee = ! $doNotCharge && ! $charge->isFree();

            if ($postsFee && $expectedFee !== null && bccomp($expectedFee, $feeAmount, 2) !== 0) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_FEE_CHANGED);
            }

            $feeLine = null;

            if ($postsFee) {
                $posted = ($this->postUnitPolicyFee)($actor, $locked, $policy, $charge->amount, __('Cancellation fee'), 'cancel', $businessDate->toDateString());

                if ($posted->isRefused()) {
                    return UnitStayResult::refused((string) $posted->refusal);
                }

                $feeLine = $posted->line();
            }

            $removedAddOnIds = $removeAddOns ? ($this->removeUnpostedAddOns)($actor, $locked) : [];

            $released = ($this->releaseUnitRooms)($locked);

            $locked->status = UnitStatus::Cancelled;
            $locked->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_cancelled')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'fee' => $feeAmount,
                    'fee_folio_line_id' => $feeLine?->id,
                    'did_not_charge' => $doNotCharge,
                    'within_free_window' => $charge->withinFreeWindow,
                    'reason' => $reason,
                    'removed_add_on_ids' => $removedAddOnIds,
                    'released_room_ids' => $released['room_ids'],
                    'released_bed_ids' => $released['bed_ids'],
                    'business_date' => $businessDate->toDateString(),
                ])
                ->log('Unit cancelled');

            ($this->syncReservationStatus)($locked->reservation);

            return UnitStayResult::done($locked);
        });
    }
}

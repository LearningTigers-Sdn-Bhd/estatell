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

class MarkNoShow
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
     * Mark one Confirmed unit as a no-show: set `UnitStatus::NoShow`, post the no-show policy fee and sync the
     * reservation status. A no-show is marked on or after the arrival date, by the business date. The arguments,
     * the lock, the fee rules, the add-on option and the release of the rooms are those of `CancelUnit`. The night audit's
     * `ResolveMissedArrivals` calls this for each unit its policy marks.
     * Permissions are checked by the route. See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null, bool $removeAddOns = false): UnitStayResult
    {
        return DB::transaction(function () use ($actor, $unit, $doNotCharge, $reason, $expectedFee, $removeAddOns): UnitStayResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if (! $locked->status->canTransitionTo(UnitStatus::NoShow)) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
            }

            if ($businessDate->toDateString() < $locked->arrival_date->toDateString()) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_ARRIVAL_NOT_REACHED);
            }

            $reason = filled($reason) ? trim((string) $reason) : null;

            if ($doNotCharge && $reason === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_REASON_REQUIRED);
            }

            $policy = RoomRevenuePolicy::query()->where('property_id', $locked->property_id)->where('code', SystemTransactionCode::NoShowRevenue)->first();

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
                $posted = ($this->postUnitPolicyFee)($actor, $locked, $policy, $charge->amount, __('No-show fee'), 'no_show', $businessDate->toDateString());

                if ($posted->isRefused()) {
                    return UnitStayResult::refused((string) $posted->refusal);
                }

                $feeLine = $posted->line();
            }

            $removedAddOnIds = $removeAddOns ? ($this->removeUnpostedAddOns)($actor, $locked) : [];

            $released = ($this->releaseUnitRooms)($locked);

            $locked->status = UnitStatus::NoShow;
            $locked->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_no_show')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'fee' => $feeAmount,
                    'fee_folio_line_id' => $feeLine?->id,
                    'did_not_charge' => $doNotCharge,
                    'reason' => $reason,
                    'removed_add_on_ids' => $removedAddOnIds,
                    'released_room_ids' => $released['room_ids'],
                    'released_bed_ids' => $released['bed_ids'],
                    'business_date' => $businessDate->toDateString(),
                ])
                ->log('Unit marked as no-show');

            ($this->syncReservationStatus)($locked->reservation);

            return UnitStayResult::done($locked);
        });
    }
}

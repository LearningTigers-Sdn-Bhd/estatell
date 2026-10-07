<?php

namespace App\Actions\FrontDesk;

use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;
use Throwable;

class EarlyCheckOut
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private ChangeUnitDates $changeUnitDates,
        private CheckOutAndRefund $checkOutAndRefund,
    ) {}

    /**
     * Check a CheckedIn unit out before its departure date, in one step: `ChangeUnitDates` moves the departure to
     * the business date (the early departure fee, the unused nights deleted), then `CheckOutAndRefund` refunds the
     * deposit and checks the unit out. A refusal of either step rolls both back, so a fee is never left on a unit
     * that is still in house. A unit that arrived on the business date has no night to keep and is refused with
     * `invalid_stay` by `ChangeUnitDates`; a departure on or before the business date is refused with `not_early`.
     * The arguments after the unit are those of `ChangeUnitDates` and `CheckOutAndRefund`. Permissions
     * (`front-desk.check-out` and `reservations.manage`) are checked by the route.
     * See docs/18-front-desk/01-check-in-and-check-out.md#early-check-out.
     */
    public function __invoke(User $actor, ReservationUnit $unit, bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null, ?string $refundAmount = null, ?string $reference = null, ?string $idempotencyKey = null): UnitStayResult
    {
        DB::beginTransaction();

        try {
            $result = $this->run($actor, $unit, $doNotCharge, $reason, $expectedFee, $refundAmount, $reference, $idempotencyKey);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }

    private function run(User $actor, ReservationUnit $unit, bool $doNotCharge, ?string $reason, ?string $expectedFee, ?string $refundAmount, ?string $reference, ?string $idempotencyKey): UnitStayResult
    {
        $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

        if ($locked->status !== UnitStatus::CheckedIn) {
            return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
        }

        $businessDate = $this->currentBusinessDate->date($locked->property);

        if ($businessDate === null) {
            return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
        }

        if ($businessDate->toDateString() >= $locked->departure_date->toDateString()) {
            return UnitStayResult::refused(UnitStayResult::REFUSAL_NOT_EARLY);
        }

        $dates = ($this->changeUnitDates)($actor, $locked, $locked->arrival_date, $businessDate, $doNotCharge, $reason, $expectedFee);

        if ($dates->isRefused()) {
            return UnitStayResult::refused((string) $dates->refusal);
        }

        return ($this->checkOutAndRefund)($actor, $locked->refresh(), $refundAmount, $reference, $idempotencyKey);
    }
}

<?php

namespace App\Actions\FrontDesk;

use App\Data\Reservations\CheckOutCollectData;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\EarlyCheckOutRule;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;
use Throwable;

class EarlyCheckOut
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private ChangeUnitDates $changeUnitDates,
        private CheckOutAndRefund $checkOutAndRefund,
        private EarlyCheckOutRule $earlyCheckOutRule,
        private PostPendingNight $postPendingNight,
    ) {}

    /**
     * Check a CheckedIn unit out before its departure date, in one step: `ChangeUnitDates` moves the departure to
     * the business date (the early departure fee, the unused nights deleted), then `CheckOutAndRefund` collects the
     * balance, refunds the deposit and checks the unit out. A refusal of either step rolls both back, so a fee is never left on a unit
     * that is still in house. The new departure is the stay day (`StayDay`): the business date, or the calendar date while
     * the night audit is pending, when `PostPendingNight` first posts the night of the business date and its add-ons.
     * `$leftYesterday` (only while the audit is pending, a reason is required) moves the departure to the business date
     * and posts nothing for it. A unit that arrived on the new departure has no night to keep and is refused with
     * `arrived_today`, an Overdue audit with `night_audit_pending`, and a departure on or before the stay day with
     * `not_early` (`EarlyCheckOutRule`).
     * The arguments after the unit are those of `ChangeUnitDates` and `CheckOutAndRefund`. Permissions
     * (`front-desk.check-out` and `reservations.manage`) are checked by the route.
     * See docs/18-front-desk/01-check-in-and-check-out.md#early-check-out.
     */
    public function __invoke(User $actor, ReservationUnit $unit, bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null, ?string $refundAmount = null, ?CheckOutCollectData $collect = null, ?string $idempotencyKey = null, bool $leftYesterday = false): UnitStayResult
    {
        DB::beginTransaction();

        try {
            $result = $this->run($actor, $unit, $doNotCharge, $reason, $expectedFee, $refundAmount, $collect, $idempotencyKey, $leftYesterday);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }

    private function run(User $actor, ReservationUnit $unit, bool $doNotCharge, ?string $reason, ?string $expectedFee, ?string $refundAmount, ?CheckOutCollectData $collect, ?string $idempotencyKey, bool $leftYesterday): UnitStayResult
    {
        $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

        if ($locked->status !== UnitStatus::CheckedIn) {
            return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
        }

        $businessDate = $this->currentBusinessDate->date($locked->property);

        if ($businessDate === null) {
            return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
        }

        $refusal = $this->earlyCheckOutRule->refusal($locked, $businessDate->toDateString(), $leftYesterday);

        if ($refusal !== null) {
            return UnitStayResult::refused($refusal, $refusal === UnitStayResult::REFUSAL_NIGHT_AUDIT_PENDING ? [$businessDate->format('j M')] : []);
        }

        if ($leftYesterday && ! filled($reason)) {
            return UnitStayResult::refused(UnitStayResult::REFUSAL_REASON_REQUIRED);
        }

        if (! $leftYesterday) {
            try {
                ($this->postPendingNight)($actor, $locked);
            } catch (UnitStayRefused $refused) {
                return $refused->result;
            }
        }

        $newDeparture = $this->earlyCheckOutRule->newDeparture($locked->property, $businessDate, $leftYesterday);
        $dates = ($this->changeUnitDates)($actor, $locked, $locked->arrival_date, $newDeparture, $doNotCharge, $reason, $expectedFee);

        if ($dates->isRefused()) {
            return UnitStayResult::refused((string) $dates->refusal);
        }

        return ($this->checkOutAndRefund)($actor, $locked->refresh(), $refundAmount, $collect, $idempotencyKey);
    }
}

<?php

namespace App\Actions\FrontDesk;

use App\Actions\Payments\RefundCancelledUnits;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class CancelUnitAndRefund
{
    public function __construct(
        private CancelUnit $cancelUnit,
        private RefundCancelledUnits $refundCancelledUnits,
    ) {}

    /**
     * Staff cancel one unit, and what it leaves as Curlec credit goes back to the guest, in one transaction. `$canRefund` is
     * false for a user without `payment.refund`, and the credit then stays on the folio, as at check-out.
     * See docs/29-booking-engine/05-cancel-and-refund.md#cases.
     */
    public function __invoke(User $actor, ReservationUnit $unit, bool $canRefund, bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null, bool $removeAddOns = false): UnitStayResult
    {
        DB::beginTransaction();

        try {
            $result = ($this->cancelUnit)($actor, $unit, $doNotCharge, $reason, $expectedFee, $removeAddOns);

            if ($result->isRefused()) {
                DB::rollBack();

                return $result;
            }

            if ($canRefund) {
                $refund = ($this->refundCancelledUnits)($actor, $unit->reservation()->firstOrFail(), [$unit->id], $reason);

                if ($refund->isRefused()) {
                    DB::rollBack();

                    return UnitStayResult::refused((string) $refund->refusal);
                }
            }

            DB::commit();

            return $result;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }
}

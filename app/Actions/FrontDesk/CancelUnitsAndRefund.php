<?php

namespace App\Actions\FrontDesk;

use App\Actions\Payments\RefundCancelledUnits;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class CancelUnitsAndRefund
{
    public function __construct(
        private CancelUnits $cancelUnits,
        private RefundCancelledUnits $refundCancelledUnits,
    ) {}

    /**
     * Staff cancel the chosen units, and what they leave as Curlec credit goes back to the guest, in one transaction.
     * `$canRefund` is false for a user without `payment.refund`, and the credit then stays on the folios, as at check-out.
     * See docs/29-booking-engine/05-cancel-and-refund.md#cases.
     *
     * @param  list<int>  $unitIds
     * @param  array<int, string|null>  $expectedFees
     */
    public function __invoke(User $actor, Reservation $reservation, array $unitIds, bool $canRefund, bool $doNotCharge = false, ?string $reason = null, bool $removeAddOns = false, array $expectedFees = []): CancelUnitsResult
    {
        DB::beginTransaction();

        try {
            $result = ($this->cancelUnits)($actor, $reservation, $unitIds, $doNotCharge, $reason, $removeAddOns, $expectedFees);

            if ($result->isRefused()) {
                DB::rollBack();

                return $result;
            }

            if ($canRefund) {
                $refund = ($this->refundCancelledUnits)($actor, $reservation, $unitIds, $reason);

                if ($refund->isRefused()) {
                    DB::rollBack();

                    return CancelUnitsResult::refused((string) $refund->refusal);
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

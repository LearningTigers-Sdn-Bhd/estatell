<?php

namespace App\Actions\BookingEngine;

use App\Actions\FrontDesk\CancelUnits;
use App\Actions\Payments\RefundCancelledUnits;
use App\Models\BookingHold;
use App\Models\Reservation;
use App\Services\BookingEngine\OnlineCancellationQuotes;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Throwable;

class CancelBookingOnline
{
    public function __construct(
        private OnlineCancellationQuotes $quotes,
        private CancelUnits $cancelUnits,
        private RefundCancelledUnits $refundCancelledUnits,
        private NotifyOnlineCancellation $notifyOnlineCancellation,
    ) {}

    /**
     * Cancel a guest's whole online booking and ask Curlec for the refund, in one transaction, so neither happens alone.
     * `$expectedRefund` is the amount the guest saw: when it differs, for example because the free window has closed, it is
     * refused and nothing is written. The actor is null, because a guest is not a user. The guest and staff are told after
     * the commit. See docs/29-booking-engine/05-cancel-and-refund.md#cases.
     */
    public function __invoke(BookingHold $hold, string $expectedRefund): CancelBookingOnlineResult
    {
        $reservation = Reservation::query()->where('property_id', $hold->property_id)->findOrFail($hold->reservation_id);
        $quote = $this->quotes->for($reservation);

        if (! $quote->canCancel()) {
            return CancelBookingOnlineResult::closed($quote);
        }

        if (! BigDecimal::of($expectedRefund)->isEqualTo($quote->refund)) {
            return CancelBookingOnlineResult::refundChanged($quote->refund);
        }

        $unitIds = array_keys($quote->fees);
        $reason = __('Cancelled by the guest online');

        DB::beginTransaction();

        try {
            $cancelled = ($this->cancelUnits)(null, $reservation, $unitIds, reason: $reason, expectedFees: $quote->fees);

            if ($cancelled->isRefused()) {
                DB::rollBack();

                return CancelBookingOnlineResult::refused();
            }

            $refund = ($this->refundCancelledUnits)(null, $reservation, $unitIds, $reason);

            if ($refund->isRefused()) {
                DB::rollBack();

                return CancelBookingOnlineResult::refused();
            }

            if (! BigDecimal::of($refund->amount)->isEqualTo($quote->refund)) {
                DB::rollBack();

                return CancelBookingOnlineResult::refundChanged($refund->amount);
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        ($this->notifyOnlineCancellation)($reservation, $quote);

        return CancelBookingOnlineResult::done($quote->refund);
    }
}

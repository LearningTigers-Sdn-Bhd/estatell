<?php

namespace App\Services\Kiosk;

use App\Models\ReservationUnit;
use App\Services\Concierge\ConciergeStays;
use App\Services\FrontDesk\SelfCheckInPayment;

/**
 * Whether a guest may check in alone at the kiosk, as a kiosk error code. The only condition beyond the check-in
 * window (which `KioskStays` already applies to every by-id call) is the paid-enough rule of `SelfCheckInPayment`
 * (decision D19): the unit folio holds payments covering what Collect now would take, and the property's check-in time
 * (same rule as the Concierge page, `ConciergeStays::checkInTimeReached`). Estatell has no other
 * balance rule at check-in, so `balance_not_settled` belongs to check-out. Reads only.
 */
class KioskEligibility
{
    public const string DEPOSIT_NOT_PAID = 'deposit_not_paid';

    public const string CHECK_IN_TIME_NOT_REACHED = 'check_in_time_not_reached';

    public function __construct(private SelfCheckInPayment $payment, private KioskWalkInHolds $holds, private ConciergeStays $concierge) {}

    /**
     * @return string|null Null when the guest may check in, else the reason code.
     */
    public function reason(ReservationUnit $stay): ?string
    {
        if ($this->holds->expired($stay->property, $stay->id)) {
            return 'hold_expired';
        }

        if (! $this->concierge->checkInTimeReached($stay)) {
            return self::CHECK_IN_TIME_NOT_REACHED;
        }

        return $this->payment->isPaidEnough($stay) ? null : self::DEPOSIT_NOT_PAID;
    }
}

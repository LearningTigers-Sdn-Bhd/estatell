<?php

namespace App\Services\Kiosk;

use App\Models\ReservationUnit;
use App\Services\FrontDesk\SelfCheckInPayment;

/**
 * Whether a guest may check in alone at the kiosk, as a kiosk error code. The only condition beyond the check-in
 * window (which `KioskStays` already applies to every by-id call) is the paid-enough rule of `SelfCheckInPayment`
 * (decision D19): the unit folio holds payments covering what Collect now would take. Estatell has no other
 * balance rule at check-in, so `balance_not_settled` belongs to check-out. Reads only.
 */
class KioskEligibility
{
    public const string DEPOSIT_NOT_PAID = 'deposit_not_paid';

    public function __construct(private SelfCheckInPayment $payment) {}

    /**
     * @return string|null Null when the guest may check in, else the reason code.
     */
    public function reason(ReservationUnit $stay): ?string
    {
        return $this->payment->isPaidEnough($stay) ? null : self::DEPOSIT_NOT_PAID;
    }
}

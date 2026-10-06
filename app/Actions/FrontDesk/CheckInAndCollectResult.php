<?php

namespace App\Actions\FrontDesk;

use App\Models\Payment;

/**
 * What `CheckInAndCollect` returns: the check-in result, the payments taken and the refusal code of a payment that
 * failed after the unit was checked in (a refusal of `RecordPayment`).
 */
class CheckInAndCollectResult
{
    /**
     * @param  list<Payment>  $payments
     */
    public function __construct(
        public readonly UnitStayResult $stay,
        public readonly array $payments = [],
        public readonly ?string $paymentRefusal = null,
    ) {}
}

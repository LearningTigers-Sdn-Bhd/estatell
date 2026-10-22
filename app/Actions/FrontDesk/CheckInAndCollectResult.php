<?php

namespace App\Actions\FrontDesk;

use App\Models\Payment;

/**
 * What `CheckInAndCollect` returns: the check-in result, the payments taken and the refusal code of a Collect now payment
 * that was refused (a refusal of `RecordPayment`). With a payment refusal nothing was saved: the unit is not checked in.
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

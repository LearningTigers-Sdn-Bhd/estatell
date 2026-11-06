<?php

namespace App\Services\Payments;

use App\Models\GatewayPayment;
use App\Models\Payment;
use Brick\Math\BigDecimal;

/**
 * What a cancelled unit gives back through Curlec. `held` is the Curlec money its folios still hold, `isShort` is true
 * when the fee still to post is more than the folio's credit, and `parts` are the payments to refund, with their shares.
 */
class CancelledUnitShare
{
    /**
     * @param  list<array{payment: Payment, gatewayPayment: GatewayPayment, amount: string}>  $parts
     */
    public function __construct(
        public readonly BigDecimal $held,
        public readonly bool $isShort,
        public readonly array $parts,
    ) {}

    public function amount(): BigDecimal
    {
        return array_reduce($this->parts, fn (BigDecimal $sum, array $part): BigDecimal => $sum->plus($part['amount']), BigDecimal::zero());
    }
}

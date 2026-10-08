<?php

namespace App\Services\Reports;

use Brick\Math\BigDecimal;

/**
 * The ledger of one folio up to a date, worked out by `FolioBalancesOnDate`. Every figure is a plain sum of the
 * lines posted on or before the date, so `charges - received + refunded = balance`.
 */
final readonly class FolioBalanceOnDate
{
    public function __construct(
        public int $folioId,
        public BigDecimal $charges,
        public BigDecimal $received,
        public BigDecimal $refunded,
        public BigDecimal $securityDeposit,
        public BigDecimal $balance,
    ) {}

    /**
     * What was paid net of refunds as a negative amount, so that charges plus payments equal the balance.
     */
    public function payments(): BigDecimal
    {
        return $this->refunded->minus($this->received);
    }
}

<?php

namespace App\Services\Payments;

use App\Services\Catalogues\TaxCalculationResult;
use Brick\Money\Money;

/**
 * What `PaymentQuote` returns. `surcharge` is the fee as the method gives it (before inclusive tax is taken out),
 * `surchargeNet` and `surchargeTax` split it for the folio, and `total` is what the guest pays: the amount plus the
 * fee and any exclusive tax on it. `tax` is null when there is no fee. A refusal leaves every amount null.
 */
class PaymentQuoteResult
{
    private function __construct(
        public readonly ?Money $amount,
        public readonly ?Money $surcharge,
        public readonly ?Money $surchargeNet,
        public readonly ?Money $surchargeTax,
        public readonly ?Money $total,
        public readonly ?TaxCalculationResult $tax,
        public readonly ?string $refusal,
    ) {}

    public static function quoted(Money $amount, Money $surcharge, ?TaxCalculationResult $tax): self
    {
        $gross = $tax->grossAmount ?? $surcharge;
        $net = $tax->netAmount ?? $surcharge;

        return new self($amount, $surcharge, $net, $gross->minus($net), $amount->plus($gross), $tax, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, null, null, null, null, null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

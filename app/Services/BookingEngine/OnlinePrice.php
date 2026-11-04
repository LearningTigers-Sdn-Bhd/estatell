<?php

namespace App\Services\BookingEngine;

use Brick\Money\Money;

/**
 * What one room or bed costs for a stay, split by where the guest pays, or the reason it cannot be priced.
 * `onlineTotal` is `onlineSubtotal` plus the exclusive taxes of `onlineTaxes`.
 */
class OnlinePrice
{
    /**
     * @param  list<OnlineTaxLine>  $onlineTaxes
     * @param  list<OnlineTaxLine>  $deskTaxes
     */
    private function __construct(
        public readonly ?Money $onlineSubtotal,
        public readonly array $onlineTaxes,
        public readonly ?Money $onlineTotal,
        public readonly array $deskTaxes,
        public readonly ?Money $deskTotal,
        public readonly ?string $refusal,
    ) {}

    /**
     * @param  list<OnlineTaxLine>  $onlineTaxes
     * @param  list<OnlineTaxLine>  $deskTaxes
     */
    public static function priced(Money $onlineSubtotal, array $onlineTaxes, Money $onlineTotal, array $deskTaxes, Money $deskTotal): self
    {
        return new self($onlineSubtotal, $onlineTaxes, $onlineTotal, $deskTaxes, $deskTotal, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, [], null, [], null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

<?php

namespace App\Services\Rates\Pricing;

use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * The price of one night: base, extra guests and children, and supplement. The discount comes from the long-stay rule.
 */
class NightPrice
{
    public function __construct(
        public readonly CarbonInterface $date,
        public readonly Money $base,
        public readonly Money $extra,
        public readonly Money $supplement,
        public readonly Money $discountAmount,
    ) {}

    public static function of(CarbonInterface $date, Money $base, Money $extra, Money $supplement): self
    {
        return new self($date, $base, $extra, $supplement, Money::zero($base->getCurrency()));
    }

    public function withDiscount(Money $discountAmount): self
    {
        return new self($this->date, $this->base, $this->extra, $this->supplement, $discountAmount);
    }

    public function total(): Money
    {
        return $this->base->plus($this->extra)->plus($this->supplement);
    }

    public function net(): Money
    {
        return $this->total()->minus($this->discountAmount);
    }
}

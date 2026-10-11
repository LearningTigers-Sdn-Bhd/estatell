<?php

namespace App\Services\Reservations;

use Brick\Money\Money;

/**
 * What Change price would do, or the refusal code. `nights` are the ticked nights whose price changes; a night at
 * the same price with no discount is skipped. `businessDate` is set when a night is charged.
 */
class NightPricePlan
{
    /**
     * @param  list<PlannedNightPrice>  $nights
     */
    private function __construct(
        public readonly ?string $refusal,
        public readonly array $nights = [],
        public readonly ?string $businessDate = null,
        public readonly ?Money $difference = null,
    ) {}

    /**
     * @param  list<PlannedNightPrice>  $nights
     */
    public static function planned(array $nights, ?string $businessDate, Money $difference): self
    {
        return new self(null, $nights, $businessDate, $difference);
    }

    public static function refused(string $refusal): self
    {
        return new self($refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

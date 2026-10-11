<?php

namespace App\Services\Rates;

use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * The price of one day use block, or the reason it cannot be priced. The refusal codes are `RateQuote` codes.
 */
class DayUseQuote
{
    private function __construct(
        public readonly ?Money $amount,
        public readonly ?string $refusal,
        public readonly ?CarbonInterface $refusalDate,
    ) {}

    public static function quoted(Money $amount): self
    {
        return new self($amount, null, null);
    }

    public static function refused(string $reason, ?CarbonInterface $date = null): self
    {
        return new self(null, $reason, $date);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

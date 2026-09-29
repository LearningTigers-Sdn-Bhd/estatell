<?php

namespace App\Services\Catalogues;

use Brick\Money\Money;

class TaxCalculationResult
{
    /** @param list<CalculatedTax> $taxes */
    private function __construct(
        public readonly ?Money $netAmount,
        public readonly array $taxes,
        public readonly ?Money $grossAmount,
        public readonly ?string $refusalReason,
    ) {}

    /** @param list<CalculatedTax> $taxes */
    public static function calculated(Money $netAmount, array $taxes, Money $grossAmount): self
    {
        return new self($netAmount, $taxes, $grossAmount, null);
    }

    public static function refused(string $reason): self
    {
        return new self(null, [], null, $reason);
    }

    public function isCalculated(): bool
    {
        return $this->refusalReason === null;
    }
}

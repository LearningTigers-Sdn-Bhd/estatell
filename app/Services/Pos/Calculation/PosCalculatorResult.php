<?php

namespace App\Services\Pos\Calculation;

/**
 * The priced order, or the reason it cannot be priced (`refusal`). The order totals add up exactly:
 * `grandTotal = subtotal + serviceChargeTotal + taxTotal`.
 */
final readonly class PosCalculatorResult
{
    /**
     * @param  list<PosCalculatorLineResult>  $lines
     */
    private function __construct(
        public array $lines,
        public string $subtotal,
        public string $serviceChargeTotal,
        public string $taxTotal,
        public string $grandTotal,
        public ?string $refusal,
    ) {}

    /**
     * @param  list<PosCalculatorLineResult>  $lines
     */
    public static function calculated(array $lines, string $subtotal, string $serviceChargeTotal, string $taxTotal, string $grandTotal): self
    {
        return new self($lines, $subtotal, $serviceChargeTotal, $taxTotal, $grandTotal, null);
    }

    public static function refused(string $refusal): self
    {
        return new self([], '0.00', '0.00', '0.00', '0.00', $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

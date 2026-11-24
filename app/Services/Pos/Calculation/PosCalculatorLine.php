<?php

namespace App\Services\Pos\Calculation;

/**
 * One order line to price. `key` is the line id (or any sortable key before it is saved); it breaks ties when whole sen
 * of an order discount are shared out. Voided lines are left out by the caller.
 */
final readonly class PosCalculatorLine
{
    /**
     * @param  list<PosCalculatorModifier>  $modifiers
     * @param  list<PosCalculatorTax>  $taxes
     */
    public function __construct(
        public int $key,
        public string $unitPrice,
        public int $quantity,
        public bool $priceIncludesTax,
        public array $taxes,
        public bool $serviceChargeExempt = false,
        public bool $isEntitlementCovered = false,
        public array $modifiers = [],
        public ?PosCalculatorDiscount $discount = null,
        public bool $excludeAddonsFromDiscount = false,
    ) {}
}

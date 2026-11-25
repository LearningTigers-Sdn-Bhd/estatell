<?php

namespace App\Services\Pos\Calculation;

/**
 * The money of one line. `net + serviceCharge + taxTotal` is the line total, and equals `payableBase` for a nett price.
 */
final readonly class PosCalculatorLineResult
{
    /**
     * @param  list<PosCalculatorTaxResult>  $taxes
     */
    public function __construct(
        public int $key,
        public bool $isPackagingFee,
        public string $lineGross,
        public string $itemDiscount,
        public string $orderDiscountShare,
        public string $payableBase,
        public string $netAmount,
        public string $serviceChargeAmount,
        public array $taxes,
        public string $taxTotal,
        public string $lineTotal,
    ) {}
}

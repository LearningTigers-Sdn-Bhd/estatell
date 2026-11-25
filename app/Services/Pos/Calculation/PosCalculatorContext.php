<?php

namespace App\Services\Pos\Calculation;

/**
 * What the order as a whole brings to the maths: the dining option's service charge and packaging fee, the order
 * discount, whether an SST registration number is on file, and whether the guest is foreign (null when not known).
 */
final readonly class PosCalculatorContext
{
    /**
     * @param  string  $serviceChargeRate  A percent, such as `10.00`.
     * @param  list<PosCalculatorTax>  $packagingTaxes  The taxes on the packaging fee's transaction code.
     */
    public function __construct(
        public bool $applyServiceCharge = false,
        public string $serviceChargeRate = '0',
        public bool $serviceChargeTaxable = false,
        public string $packagingFee = '0',
        public array $packagingTaxes = [],
        public ?PosCalculatorDiscount $orderDiscount = null,
        public bool $sstRegistered = true,
        public ?bool $guestIsForeign = null,
    ) {}
}

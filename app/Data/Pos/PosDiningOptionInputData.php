<?php

namespace App\Data\Pos;

use App\Enums\PosDiningKind;
use Spatie\LaravelData\Data;

/**
 * The fields of a dining option form.
 */
class PosDiningOptionInputData extends Data
{
    public function __construct(
        public string $name,
        public PosDiningKind $kind,
        public bool $applyServiceCharge = true,
        public string $serviceChargeRate = '10.00',
        public bool $serviceChargeTaxable = false,
        public string $packagingFee = '0.00',
        public bool $isDefault = false,
        public bool $active = true,
    ) {}
}

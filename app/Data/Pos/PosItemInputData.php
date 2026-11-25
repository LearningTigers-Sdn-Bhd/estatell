<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * The fields of a menu item form. Every item keeps at least one variant, which holds its price.
 */
class PosItemInputData extends Data
{
    /**
     * @param  list<PosItemVariantInputData>  $variants
     */
    public function __construct(
        public string $name,
        public int $posCategoryId,
        public array $variants,
        public ?int $transactionCodeId = null,
        public ?int $mealPeriodId = null,
        public ?string $internalName = null,
        public ?string $description = null,
        public ?string $skuPrefix = null,
        public bool $priceIncludesTax = false,
        public bool $serviceChargeExempt = false,
        public ?string $cookingGuide = null,
        public int $sortOrder = 0,
        public bool $active = true,
        public bool $soldByWeight = false,
        public ?string $weightUnit = null,
        public ?string $weightUnitDivisor = null,
    ) {}
}

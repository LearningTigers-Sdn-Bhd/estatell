<?php

namespace App\Data\Pos;

use App\Enums\PosPricingType;
use Spatie\LaravelData\Data;

/**
 * One variant row of a menu item form. A row with an `id` updates that variant, one without creates a new variant.
 */
class PosItemVariantInputData extends Data
{
    public function __construct(
        public string $price,
        public string $name = 'Regular',
        public ?int $id = null,
        public PosPricingType $pricingType = PosPricingType::Fixed,
        public ?string $sku = null,
        public ?string $barcode = null,
        public ?string $cost = null,
        public int $sortOrder = 0,
        public bool $active = true,
    ) {}
}

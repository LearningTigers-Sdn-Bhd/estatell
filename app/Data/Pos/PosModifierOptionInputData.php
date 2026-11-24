<?php

namespace App\Data\Pos;

use App\Enums\PosAvailability;
use Spatie\LaravelData\Data;

/**
 * One choice row of a modifier group form. A row with an `id` updates that option.
 */
class PosModifierOptionInputData extends Data
{
    public function __construct(
        public string $name,
        public ?int $id = null,
        public ?string $internalName = null,
        public string $priceDelta = '0.00',
        public PosAvailability $availability = PosAvailability::All,
        public int $sortOrder = 0,
        public bool $active = true,
    ) {}
}

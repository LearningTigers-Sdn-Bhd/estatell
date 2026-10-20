<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * The room night behind a live room line: its unit, its id and its price per night. The Edit line Dialog changes this
 * price through Change price. `canChangePrice` is false for a user without `reservations.change-price`, which makes the
 * price read only.
 */
class ManualLineNightData extends Data
{
    public function __construct(
        public int $unitId,
        public int $nightId,
        public string $price,
        public bool $canChangePrice,
    ) {}
}

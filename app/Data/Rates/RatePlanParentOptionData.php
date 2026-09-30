<?php

namespace App\Data\Rates;

use Spatie\LaravelData\Data;

/** A plan another plan can follow. */
class RatePlanParentOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}

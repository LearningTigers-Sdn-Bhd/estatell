<?php

namespace App\Data\Facilities;

use Spatie\LaravelData\Data;

/** One tax of a facility booking quote. */
class FacilityQuoteTaxData extends Data
{
    public function __construct(
        public string $name,
        public string $amount,
        public bool $inclusive,
    ) {}
}

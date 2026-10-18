<?php

namespace App\Data\Rates;

use Spatie\LaravelData\Data;

/**
 * A company linked to a rate plan, for the plan editor's Portal tab.
 */
class RatePlanPortalCompanyData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}

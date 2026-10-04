<?php

namespace App\Services\CityLedger;

/**
 * The answer of `CreditCheck`: allowed or not, with the figures behind it. `overBy` is how far exposure plus the
 * amount goes above the limit, `0.00` when allowed.
 */
class CreditCheckResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $exposure,
        public readonly ?string $limit,
        public readonly string $additional,
        public readonly string $overBy,
    ) {}
}

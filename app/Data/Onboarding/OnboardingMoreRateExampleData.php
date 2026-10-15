<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

/**
 * The example column of a Follows row: the Basic Rate weekday price of the first room type and the price that follows
 * from it. A row with a blank or half-typed price gives null for both.
 */
class OnboardingMoreRateExampleData extends Data
{
    public function __construct(
        public string $key,
        public ?string $parentAmount,
        public ?string $result,
    ) {}
}

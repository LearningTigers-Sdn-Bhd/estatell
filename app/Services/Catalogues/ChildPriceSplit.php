<?php

namespace App\Services\Catalogues;

use App\Enums\AgeBandPricing;

/**
 * How the children of one unit are charged by a per-guest charge item. `adultChildren` are children charged at the
 * adult price (an age past the last group, or no age recorded). `groups` are the billable age groups: Free groups and
 * groups with no children are left out.
 */
final readonly class ChildPriceSplit
{
    /**
     * @param  list<array{age_group_id: int, count: int, pricing: AgeBandPricing, value: string|null}>  $groups
     */
    public function __construct(
        public int $adultChildren,
        public array $groups,
    ) {}
}

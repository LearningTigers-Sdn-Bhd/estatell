<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * The property's standing rules: when check-in and check-out are, the house rules the owner wrote, and the owner's
 * wording for each charge policy. Rate-specific terms of a booking are not here.
 */
class ConciergePoliciesData extends Data
{
    /**
     * @param  list<ConciergePolicyTermData>  $terms
     */
    public function __construct(
        public string $checkInTime,
        public string $checkOutTime,
        public ?string $houseRules,
        public array $terms = [],
    ) {}
}

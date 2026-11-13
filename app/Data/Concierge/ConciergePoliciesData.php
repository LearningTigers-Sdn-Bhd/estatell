<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * The property's standing rules: when check-in and check-out are, the house rules, room terms, payment and deposit
 * terms and other policies the owner wrote, and the owner's
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
        public ?string $roomPolicy,
        public ?string $paymentPolicy,
        public ?string $otherPolicies,
        public ?string $smoking,
        public ?string $pets,
        public array $terms = [],
    ) {}
}

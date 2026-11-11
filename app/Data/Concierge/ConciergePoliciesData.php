<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * The property's standing rules: when check-in and check-out are, and the house rules the owner wrote. Rate-specific
 * terms such as cancellation fees belong to the booking and are not here.
 */
class ConciergePoliciesData extends Data
{
    public function __construct(
        public string $checkInTime,
        public string $checkOutTime,
        public ?string $houseRules,
    ) {}
}

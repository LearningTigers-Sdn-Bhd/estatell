<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;

/**
 * One rate plan offered for a search line. `total` is the line total with the auto-added items, a decimal string.
 * A blocked offer has the `reason` it cannot be booked and is shown, not hidden.
 */
class PortalOfferData extends Data
{
    public function __construct(
        public int $ratePlanId,
        public string $ratePlanName,
        public ?string $total,
        public ?string $reason,
    ) {}
}

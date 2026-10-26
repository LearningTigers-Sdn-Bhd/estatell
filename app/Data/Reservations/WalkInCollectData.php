<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the walk-in form asks to be paid: the two boxes of the Payment section, the method, its reference and the total
 * the rail showed (`expectedTotal`). The save works the total out again and refuses when it differs. There are no
 * amounts to trust: each unit's amounts come from `CheckInCollection`. `WalkInCollectData` exists only when a payment is
 * asked, so a form without `payment.record`, or with both boxes unticked, passes none.
 */
class WalkInCollectData extends Data
{
    public function __construct(
        public bool $roomCharges,
        public bool $deposit,
        public int $paymentMethodId,
        public ?string $reference,
        public string $expectedTotal,
    ) {}
}

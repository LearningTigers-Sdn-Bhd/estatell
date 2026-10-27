<?php

namespace App\Actions\FrontDesk;

/**
 * How one unit of a walk-in ended: `refusal` is the code that kept it Confirmed (a `CheckInUnit` code, or a
 * `RecordPayment` code when `paymentRefused` is true), null when it checked in. `collected` is the sum of the payments
 * taken for it, `0.00` for a unit that stayed Confirmed. `label` names the unit for the toast, such as "Room 106".
 */
class WalkInUnitResult
{
    public function __construct(
        public readonly int $unitId,
        public readonly string $label,
        public readonly ?string $refusal,
        public readonly bool $paymentRefused,
        public readonly string $collected,
    ) {}

    public function isCheckedIn(): bool
    {
        return $this->refusal === null;
    }
}

<?php

namespace App\Actions\FrontDesk;

use App\Models\ReservationUnit;
use Brick\Money\Money;

/**
 * What `ChangeUnitDates`, `ExtendStay` and `ProcessEarlyDeparture` return: the unit after the change with what the
 * form shows, or a refusal code (the codes of `UnitStayResult`, the posting refusals and the quote refusals).
 * `fee` is the early departure policy fee before tax and `feeCharged` is whether it was posted. `previousTotal` and
 * `newTotal` are the sums of the unit's night amounts. `unassignedNights` counts added nights that could not keep the
 * room or bed of the neighbouring night because it is taken.
 */
class StayDatesResult
{
    private function __construct(
        public readonly ?ReservationUnit $unit,
        public readonly ?string $refusal,
        public readonly string $fee = '0.00',
        public readonly bool $feeCharged = false,
        public readonly ?Money $previousTotal = null,
        public readonly ?Money $newTotal = null,
        public readonly int $unassignedNights = 0,
    ) {}

    public static function done(ReservationUnit $unit, string $fee, bool $feeCharged, ?Money $previousTotal = null, ?Money $newTotal = null, int $unassignedNights = 0): self
    {
        return new self($unit, null, $fee, $feeCharged, $previousTotal, $newTotal, $unassignedNights);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

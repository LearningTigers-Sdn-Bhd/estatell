<?php

namespace App\Actions\FrontDesk;

use App\Models\ReservationUnit;

/**
 * What the front desk unit Actions return: the unit after the change, or a refusal code. The posting refusal codes
 * of `PostCharge` pass through as they are when an add-on cannot post at check-in, and those of `PostFolioCharge`'s
 * callers (`folio_not_open`, `nationality_required`) when a cancellation or no-show fee cannot post. A quote refusal
 * of `RateQuote` passes through when `ReinstateUnit` cannot price the stay.
 */
class UnitStayResult
{
    public const REFUSAL_INVALID_STATUS = 'invalid_status';

    public const REFUSAL_NO_BUSINESS_DATE = 'no_business_date';

    public const REFUSAL_ARRIVAL_NOT_REACHED = 'arrival_not_reached';

    public const REFUSAL_STAY_ENDED = 'stay_ended';

    public const REFUSAL_NO_ROOM_ASSIGNED = 'no_room_assigned';

    public const REFUSAL_CARD_MISSING = 'card_missing';

    public const REFUSAL_NATIONALITY_REQUIRED = 'nationality_required';

    public const REFUSAL_CREDIT_LIMIT_EXCEEDED = 'credit_limit_exceeded';

    public const REFUSAL_BEFORE_DEPARTURE_DATE = 'before_departure_date';

    public const REFUSAL_BALANCE_NOT_SETTLED = 'balance_not_settled';

    public const REFUSAL_REASON_REQUIRED = 'reason_required';

    public const REFUSAL_FEE_CHANGED = 'fee_changed';

    public const REFUSAL_POLICY_NOT_FOUND = 'policy_not_found';

    public const REFUSAL_CHARGE_NOT_FOUND = 'charge_not_found';

    public const REFUSAL_NOT_AVAILABLE = 'not_available';

    private function __construct(
        public readonly ?ReservationUnit $unit,
        public readonly ?string $refusal,
    ) {}

    public static function done(ReservationUnit $unit): self
    {
        return new self($unit, null);
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

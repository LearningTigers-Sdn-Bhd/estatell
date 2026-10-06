<?php

namespace App\Actions\FrontDesk;

use App\Models\ReservationUnit;

/**
 * What `CheckInUnit` and `CheckOutUnit` return: the unit after the change, or a refusal code. The posting refusal codes
 * of `PostCharge` pass through as they are when an add-on cannot post at check-in.
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

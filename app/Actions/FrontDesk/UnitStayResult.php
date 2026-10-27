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

    public const REFUSAL_RECEIVES_SCHEDULED_CHARGES = 'folio_receives_scheduled_charges';

    public const REFUSAL_REASON_REQUIRED = 'reason_required';

    public const REFUSAL_FEE_CHANGED = 'fee_changed';

    public const REFUSAL_DAMAGE_NOT_ALLOWED = 'damage_not_allowed';

    public const REFUSAL_DAMAGE_PRICE_FIXED = 'damage_price_fixed';

    public const REFUSAL_POLICY_NOT_FOUND = 'policy_not_found';

    public const REFUSAL_CHARGE_NOT_FOUND = 'charge_not_found';

    public const REFUSAL_NOT_AVAILABLE = 'not_available';

    public const REFUSAL_INVALID_STAY = 'invalid_stay';

    public const REFUSAL_DATES_UNCHANGED = 'dates_unchanged';

    public const REFUSAL_ARRIVAL_LOCKED = 'arrival_locked';

    public const REFUSAL_ARRIVAL_IN_PAST = 'arrival_in_past';

    public const REFUSAL_DEPARTURE_IN_PAST = 'departure_in_past';

    public const REFUSAL_PACKAGE_PLAN = 'package_plan';

    public const REFUSAL_DAY_USE_UNIT = 'day_use_unit';

    public const REFUSAL_NOT_SAME_DAY = 'not_same_day';

    public const REFUSAL_PAST_MIDNIGHT = 'past_midnight';

    public const REFUSAL_START_TIME_INVALID = 'start_time_invalid';

    public const REFUSAL_HOURS_INVALID = 'hours_invalid';

    public const REFUSAL_AMOUNT_REQUIRED = 'amount_required';

    public const REFUSAL_OVERRIDE_REASON_REQUIRED = 'override_reason_required';

    public const REFUSAL_RESTRICTED = 'restricted';

    public const REFUSAL_NOT_AN_EXTENSION = 'not_an_extension';

    public const REFUSAL_NOT_SHORTER = 'not_shorter';

    public const REFUSAL_NOT_EARLY = 'not_early';

    public const REFUSAL_NOT_DEPARTURE_DATE = 'not_departure_date';

    public const REFUSAL_ARRIVED_TODAY = 'arrived_today';

    public const REFUSAL_NIGHT_AUDIT_PENDING = 'night_audit_pending';

    public const REFUSAL_NOT_OVERDUE = 'not_overdue';

    public const REFUSAL_LEFT_YESTERDAY_UNAVAILABLE = 'left_yesterday_unavailable';

    public const REFUSAL_UNDO_TOO_LATE = 'undo_too_late';

    public const REFUSAL_NIGHT_POSTED = 'night_posted';

    public const REFUSAL_ROOM_TAKEN = 'room_taken';

    public const REFUSAL_TIME_NOT_LATER = 'time_not_later';

    public const REFUSAL_ROOM_NOT_FREE = 'room_not_free';

    private function __construct(
        public readonly ?ReservationUnit $unit,
        public readonly ?string $refusal,
        /** @var list<string> */
        public readonly array $rooms = [],
        public readonly ?string $fee = null,
    ) {}

    /**
     * @param  string|null  $fee  The policy fee `CancelUnit` posted before tax ("0.00" when none), for `CancelUnits`.
     */
    public static function done(ReservationUnit $unit, ?string $fee = null): self
    {
        return new self($unit, null, [], $fee);
    }

    /**
     * @param  list<string>  $rooms  The rooms and dates for `folio_receives_scheduled_charges`, the amounts held for
     *                               other rooms ("MYR 540.00 for Room 102") for `balance_not_settled`, or the
     *                               business date ("20 Oct") for `night_audit_pending`, or the room and the date
     *                               ("204", "20 Oct") for `not_available` of `CheckOutOverdueStay`.
     */
    public static function refused(string $refusal, array $rooms = []): self
    {
        return new self(null, $refusal, $rooms);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

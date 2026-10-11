<?php

namespace App\Actions\Reservations;

use App\Models\Reservation;
use Carbon\CarbonInterface;

/**
 * What `CreateReservation` and `AddUnit` return: the saved reservation, or a refusal code with the line and night that
 * caused it. The `RateQuote` refusal codes are passed through as they are. `unitsWithPostedRoomLines` lists the units
 * whose posted room lines stay on their own folio because a billing mode routing rule now sends room charges to the
 * Master folio (see `SyncRoutingRules`), and `unitAdvances` the Advance money left on those units' own folios (amount
 * by unit id). Only `AddUnit` and `UpdateReservationCompany` fill them.
 */
class ReservationResult
{
    public const REFUSAL_UNITS_REQUIRED = 'units_required';

    public const REFUSAL_BOOKER_NOT_FOUND = 'booker_not_found';

    public const REFUSAL_ROOM_TYPE_NOT_FOUND = 'room_type_not_found';

    public const REFUSAL_RATE_PLAN_NOT_LINKED = 'rate_plan_not_linked';

    public const REFUSAL_INVALID_STAY = 'invalid_stay';

    public const REFUSAL_ARRIVAL_IN_PAST = 'arrival_in_past';

    public const REFUSAL_ADULTS_REQUIRED = 'adults_required';

    public const REFUSAL_CHILD_AGE_INVALID = 'child_age_invalid';

    public const REFUSAL_DISCOUNT_NOT_ALLOWED = 'discount_not_allowed';

    public const REFUSAL_RESTRICTED = 'restricted';

    public const REFUSAL_RESTRICTION_OVERRIDE_NOT_ALLOWED = 'restriction_override_not_allowed';

    public const REFUSAL_NOT_AVAILABLE = 'not_available';

    public const REFUSAL_OVERRIDE_REASON_REQUIRED = 'override_reason_required';

    public const REFUSAL_OVERRIDE_INVALID = 'override_invalid';

    public const REFUSAL_RESERVATION_CLOSED = 'reservation_closed';

    public const REFUSAL_COMPANY_NOT_AVAILABLE = 'company_not_available';

    public const REFUSAL_RATE_PLAN_NOT_LINKED_TO_COMPANY = 'rate_plan_not_linked_to_company';

    public const REFUSAL_COMPANY_PLAN_CONFLICT = 'company_plan_conflict';

    public const REFUSAL_CREDIT_LIMIT_EXCEEDED = 'credit_limit_exceeded';

    public const REFUSAL_MASTER_HAS_PAYMENTS = 'master_has_payments';

    public const REFUSAL_MASTER_HAS_AR_INVOICE = 'master_has_ar_invoice';

    public const REFUSAL_OVERRIDE_NOT_ALLOWED = 'override_not_allowed';

    public const REFUSAL_STAY_TYPE_MISMATCH = 'stay_type_mismatch';

    public const REFUSAL_STAY_TYPES_MIXED = 'stay_types_mixed';

    public const REFUSAL_DAY_USE_NOT_FOR_CHANNEL = 'day_use_not_for_channel';

    public const REFUSAL_OUTSIDE_SOLD_BETWEEN = 'outside_sold_between';

    public const REFUSAL_START_TIME_INVALID = 'start_time_invalid';

    public const REFUSAL_ROOM_REQUIRED = 'room_required';

    private function __construct(
        public readonly ?Reservation $reservation,
        public readonly ?string $refusal,
        public readonly ?int $lineIndex,
        public readonly ?CarbonInterface $date,
        /** @var list<int> */
        public readonly array $unitsWithPostedRoomLines = [],
        /** The amount held (`master_has_payments`) or the invoice number (`master_has_ar_invoice`). */
        public readonly ?string $detail = null,
        /** @var array<int, string> */
        public readonly array $unitAdvances = [],
    ) {}

    /**
     * @param  list<int>  $unitsWithPostedRoomLines
     * @param  array<int, string>  $unitAdvances
     */
    public static function created(Reservation $reservation, array $unitsWithPostedRoomLines = [], array $unitAdvances = []): self
    {
        return new self($reservation, null, null, null, $unitsWithPostedRoomLines, null, $unitAdvances);
    }

    public static function refused(string $refusal, ?int $lineIndex = null, ?CarbonInterface $date = null, ?string $detail = null): self
    {
        return new self(null, $refusal, $lineIndex, $date, [], $detail);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

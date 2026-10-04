<?php

namespace App\Actions\Reservations;

use App\Models\Reservation;
use Carbon\CarbonInterface;

/**
 * What `CreateReservation` and `AddUnit` return: the saved reservation, or a refusal code with the line and night that
 * caused it. The `RateQuote` refusal codes are passed through as they are. `unitsWithPostedRoomLines` lists the units
 * whose posted room lines stay on their own folio because a billing mode routing rule now sends room charges to the
 * Master folio (see `SyncRoutingRules`). Only `AddUnit` can fill it.
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

    public const REFUSAL_MASTER_HAS_LINES = 'master_has_lines';

    private function __construct(
        public readonly ?Reservation $reservation,
        public readonly ?string $refusal,
        public readonly ?int $lineIndex,
        public readonly ?CarbonInterface $date,
        /** @var list<int> */
        public readonly array $unitsWithPostedRoomLines = [],
    ) {}

    /**
     * @param  list<int>  $unitsWithPostedRoomLines
     */
    public static function created(Reservation $reservation, array $unitsWithPostedRoomLines = []): self
    {
        return new self($reservation, null, null, null, $unitsWithPostedRoomLines);
    }

    public static function refused(string $refusal, ?int $lineIndex = null, ?CarbonInterface $date = null): self
    {
        return new self(null, $refusal, $lineIndex, $date);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

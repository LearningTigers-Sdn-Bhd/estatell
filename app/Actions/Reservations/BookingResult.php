<?php

namespace App\Actions\Reservations;

use App\Models\Reservation;
use Carbon\CarbonInterface;

/**
 * What `CreateBooking` returns: the saved reservation, or the refusal code of the step that failed. The code is one
 * of the codes of that step's Action, or one of the booking codes below. `lineIndex`, `guestIndex` and `unitIndex`
 * point at the line, guest tab or unit that caused it, and `date` at the night.
 */
class BookingResult
{
    public const REFUSAL_BOOKER_REQUIRED = 'booker_required';

    public const REFUSAL_GUEST_NAME_REQUIRED = 'guest_name_required';

    public const REFUSAL_UNIT_NOT_FOUND = 'unit_not_found';

    public const REFUSAL_REASON_REQUIRED = 'reason_required';

    public const REFUSAL_PAST_STAY_NIGHTS_ONLY = 'past_stay_nights_only';

    public const REFUSAL_ARRIVAL_NOT_PAST = 'arrival_not_past';

    public const REFUSAL_ARRIVAL_BEFORE_GO_LIVE = 'arrival_before_go_live';

    public const REFUSAL_DEPARTURE_NOT_AFTER_TODAY = 'departure_not_after_today';

    public const REFUSAL_DEPARTURE_AFTER_TODAY = 'departure_after_today';

    private function __construct(
        public readonly ?Reservation $reservation,
        public readonly ?string $refusal,
        public readonly ?int $lineIndex,
        public readonly ?int $guestIndex,
        public readonly ?int $unitIndex,
        public readonly ?CarbonInterface $date,
        public readonly ?string $boatDirection = null,
    ) {}

    public static function created(Reservation $reservation): self
    {
        return new self($reservation, null, null, null, null, null);
    }

    public static function refused(string $refusal, ?int $lineIndex = null, ?int $guestIndex = null, ?int $unitIndex = null, ?CarbonInterface $date = null, ?string $boatDirection = null): self
    {
        return new self(null, $refusal, $lineIndex, $guestIndex, $unitIndex, $date, $boatDirection);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

<?php

namespace App\Actions\Portal;

use App\Models\Reservation;
use Carbon\CarbonInterface;

/**
 * What the portal Actions return: the reservation, or the refusal code. `lineIndex` points at the booking line that
 * caused it, `date` at the night, and `violations` holds the restriction texts of a `restricted` refusal. Codes that
 * come from the shared booking Actions pass through as they are; `PortalBookingMessages` words them for the portal.
 */
class PortalBookingResult
{
    public const string REFUSAL_COMPANY_SUSPENDED = 'company_suspended';

    public const string REFUSAL_PLAN_NOT_VISIBLE = 'plan_not_visible';

    public const string REFUSAL_PRICE_CHANGED = 'price_changed';

    public const string REFUSAL_TOO_MANY_ADULTS = 'too_many_adults';

    public const string REFUSAL_TOO_MANY_CHILDREN = 'too_many_children';

    public const string REFUSAL_BOOKING_SOURCE_MISSING = 'booking_source_missing';

    public const string REFUSAL_NOT_CANCELLABLE = 'not_cancellable';

    public const string REFUSAL_ALREADY_REQUESTED = 'already_requested';

    public const string REFUSAL_PAST_CUT_OFF = 'past_cut_off';

    public const string REFUSAL_NOT_EDITABLE = 'not_editable';

    public const string REFUSAL_GUEST_NOT_FOUND = 'guest_not_found';

    /**
     * @param  list<string>  $violations
     */
    private function __construct(
        public readonly ?Reservation $reservation,
        public readonly ?string $refusal,
        public readonly ?int $lineIndex,
        public readonly ?CarbonInterface $date,
        public readonly array $violations,
    ) {}

    public static function done(Reservation $reservation): self
    {
        return new self($reservation, null, null, null, []);
    }

    /**
     * @param  list<string>  $violations
     */
    public static function refused(string $refusal, ?int $lineIndex = null, ?CarbonInterface $date = null, array $violations = []): self
    {
        return new self(null, $refusal, $lineIndex, $date, $violations);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

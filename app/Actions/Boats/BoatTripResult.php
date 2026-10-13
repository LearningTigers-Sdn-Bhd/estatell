<?php

namespace App\Actions\Boats;

/**
 * What `SetBoatTrip` and `ClearBoatTrip` return: done, or the refusal code. A refusal writes and logs nothing.
 * `postedFareKept` is true when a fare row that is already on the folio was kept, so the staff must reverse it there.
 */
class BoatTripResult
{
    public const REFUSAL_BOATS_OFF = 'boats_off';

    public const REFUSAL_UNIT_NOT_OPEN = 'unit_not_open';

    public const REFUSAL_DEPARTURE_NOT_FOUND = 'departure_not_found';

    public const REFUSAL_TIME_REQUIRED = 'time_required';

    public const REFUSAL_DAY_USE = 'day_use';

    public const REFUSAL_FARE_NOT_FOUND = 'fare_not_found';

    private function __construct(
        public readonly ?string $refusal,
        public readonly bool $postedFareKept = false,
    ) {}

    public static function done(bool $postedFareKept = false): self
    {
        return new self(null, $postedFareKept);
    }

    public static function refused(string $refusal): self
    {
        return new self($refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

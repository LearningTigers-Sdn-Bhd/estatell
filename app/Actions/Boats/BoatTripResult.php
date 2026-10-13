<?php

namespace App\Actions\Boats;

/**
 * What `SetBoatTrip` and `ClearBoatTrip` return: done, or the refusal code. A refusal writes and logs nothing.
 */
class BoatTripResult
{
    public const REFUSAL_BOATS_OFF = 'boats_off';

    public const REFUSAL_UNIT_NOT_OPEN = 'unit_not_open';

    public const REFUSAL_DEPARTURE_NOT_FOUND = 'departure_not_found';

    public const REFUSAL_TIME_REQUIRED = 'time_required';

    public const REFUSAL_DAY_USE = 'day_use';

    private function __construct(public readonly ?string $refusal) {}

    public static function done(): self
    {
        return new self(null);
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

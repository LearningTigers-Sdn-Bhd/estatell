<?php

namespace App\Actions\Boats;

/**
 * What `UpdateBoatDeparture` and `DeleteBoatDeparture` return: done, with the number of bookings whose unit times
 * moved, or the refusal code. A refusal writes and logs nothing.
 */
class BoatDepartureResult
{
    public const REFUSAL_DEPARTURE_IN_USE = 'departure_in_use';

    private function __construct(
        public readonly ?string $refusal,
        public readonly int $bookingsChanged = 0,
    ) {}

    public static function done(int $bookingsChanged = 0): self
    {
        return new self(null, $bookingsChanged);
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

<?php

namespace App\Actions\Boats;

/**
 * What `SetBoatTrips` returns: done, or the first refusal with the unit it belongs to and that unit's label. A refusal
 * writes and logs nothing, because every change of the batch is rolled back. `postedFareKept` is true when any changed
 * trip kept a posted fare row.
 */
class BoatTripsResult
{
    private function __construct(
        public readonly ?string $refusal = null,
        public readonly ?int $unitId = null,
        public readonly ?string $unitLabel = null,
        public readonly bool $postedFareKept = false,
    ) {}

    public static function done(bool $postedFareKept = false): self
    {
        return new self(postedFareKept: $postedFareKept);
    }

    public static function refused(string $refusal, int $unitId, string $unitLabel): self
    {
        return new self($refusal, $unitId, $unitLabel);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

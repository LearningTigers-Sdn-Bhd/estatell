<?php

namespace App\Actions\Boats;

/**
 * What `SetBoatTrips` returns: done, or the first refusal with the unit it belongs to and that unit's label. A refusal
 * writes and logs nothing, because every change of the batch is rolled back.
 */
class BoatTripsResult
{
    private function __construct(
        public readonly ?string $refusal = null,
        public readonly ?int $unitId = null,
        public readonly ?string $unitLabel = null,
    ) {}

    public static function done(): self
    {
        return new self;
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

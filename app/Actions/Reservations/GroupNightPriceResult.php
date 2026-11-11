<?php

namespace App\Actions\Reservations;

/**
 * What `ChangeGroupNightPrice` returns: how many units and nights changed and how many nights were charged again, or
 * a refusal code with the unit that caused it. A refusal means nothing was written.
 */
class GroupNightPriceResult
{
    public const REFUSAL_NO_UNITS = 'no_units_selected';

    private function __construct(
        public readonly ?string $refusal,
        public readonly int $units = 0,
        public readonly int $changed = 0,
        public readonly int $corrected = 0,
        public readonly ?int $failedUnitId = null,
        public readonly ?string $failedUnit = null,
    ) {}

    public static function done(int $units, int $changed, int $corrected): self
    {
        return new self(null, $units, $changed, $corrected);
    }

    /**
     * @param  string|null  $unit  The label of the unit that refused, for the message.
     */
    public static function refused(string $refusal, ?int $unitId = null, ?string $unit = null): self
    {
        return new self($refusal, failedUnitId: $unitId, failedUnit: $unit);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

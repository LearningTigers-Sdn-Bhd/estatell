<?php

namespace App\Actions\Reservations;

use App\Models\UnitAddOn;

/**
 * What `AddGroupAddOn` returns: the units that got the add-on and every created row, or a refusal code with the unit
 * that caused it. A refusal means nothing was written.
 */
class GroupAddOnResult
{
    public const REFUSAL_NO_UNITS = 'no_units_selected';

    /**
     * @param  list<UnitAddOn>  $addOns
     */
    private function __construct(
        public readonly array $addOns,
        public readonly int $units,
        public readonly ?string $refusal,
        public readonly ?int $failedUnitId = null,
        public readonly ?string $failedUnit = null,
    ) {}

    /**
     * @param  list<UnitAddOn>  $addOns
     */
    public static function created(array $addOns, int $units): self
    {
        return new self($addOns, $units, null);
    }

    /**
     * @param  string|null  $unit  The label of the unit that refused, for the message.
     */
    public static function refused(string $refusal, ?int $unitId = null, ?string $unit = null): self
    {
        return new self([], 0, $refusal, $unitId, $unit);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}

<?php

namespace App\Actions\Transfers;

/**
 * What `SetUnitTransfer`, `ClearUnitTransfer` and `AssignTransfer` return: done, or the refusal code. A refusal writes
 * and logs nothing. `postedChargeKept` is true when a charge row already on the folio was kept, so staff must reverse it
 * there. `TransferRefusals` has the words.
 */
class UnitTransferResult
{
    public const REFUSAL_UNIT_NOT_OPEN = 'unit_not_open';

    public const REFUSAL_ROUTE_NOT_FOUND = 'route_not_found';

    public const REFUSAL_CHARGE_NOT_FOUND = 'charge_not_found';

    public const REFUSAL_VEHICLE_NOT_FOUND = 'vehicle_not_found';

    public const REFUSAL_DRIVER_NOT_FOUND = 'driver_not_found';

    private function __construct(
        public readonly ?string $refusal,
        public readonly bool $postedChargeKept = false,
    ) {}

    public static function done(bool $postedChargeKept = false): self
    {
        return new self(null, $postedChargeKept);
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
